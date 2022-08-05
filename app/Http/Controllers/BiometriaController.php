<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use CURLFile;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleXMLElement;

class BiometriaController extends Controller
{
    public function takeCode(Request $request)
    {
        $iin = $request->input('iin');
        $phone = $request->input('phone');
        $result['success'] = false;
        do {
            if (!$iin) {
                $result['message'] = 'Не передан иин';
                break;
            }
            if (!$phone) {
                $result['message'] = 'Не передан телефон';
                break;
            }
            $client = new Client(['verify' => false]);
            $response = $client->get('https://secure2.1cb.kz/fcbid-otp/api/v1/login', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode('7471656497:970908350192'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);
            $response = $response->getBody()->getContents();
            $response = json_decode($response, true);
            $token = $response['access']['hash'];
            $url = "https://secure2.1cb.kz/fcbid-otp/api/v1/send-code";
            $uuid = Str::uuid()->toString();
            DB::table('first_data')->insertGetId([
                'requestID' => $uuid,
                'token' => $token,
                'iin' => $iin,
                'phone' => $phone,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'RequestID' => $uuid,
                'Content-Type' => 'application/json',
            ];
            $body = [
                'iin' => $iin,
                'phone' => $phone,
            ];

            $res = $client->post($url, [
                'headers' => $headers,
                'body' => json_encode($body),
            ]);
            $status = $res->getStatusCode();
            $response = $res->getBody()->getContents();
            $response = json_decode($response, true);
            if (isset($response['errorCode']) && $response['errorCode'] == 404) {
                $result['message'] = 'Не найден в БМГ';
                break;
            }
            if (isset($status) && $status == 500) {
                $result['message'] = 'Не найден в БМГ';
                break;
            }
            if ($status == 200 && $response['responseCode'] == 'PROFILE_DOCUMENT_ACCESS_SUCCESS') {
                $result['success'] = true;
                break;
            }
            if ($status == 400) {
                $result['success'] = false;
                $result['message'] = 'Попробуйте позже';
                break;
            }

        } while (false);
        return response()->json($result);
    }

    public function takeDocs(Request $request)
    {
        $code = $request->input('code');
        $name = trim($request->input('name'));
        $lastName = trim($request->input('lastName'));
        $middleName = trim($request->input('middleName'));
        $iin = $request->input('iin');
        $result['success'] = false;
        do {
            if (!$code) {
                $result['message'] = 'Не передан код';
                break;
            }
            if (!$name) {
                $result['message'] = 'Не передан имя';
                break;
            }
            if (!$middleName) {
                $result['message'] = 'Не передан фамилия';
                break;
            }
            $client = new Client(['verify' => false]);

            $data = DB::table('first_data')->where('iin', $iin)->orderByDesc('id')->first();
            $uuid = $data->requestID;
            $token = $data->token;

            //$url = "https://secure2.1cb.kz/fcbid-otp/api/v1/get-pdf-document";
            $url = "https://secure2.1cb.kz/idservice/v2/advanced/digital/docs";
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'RequestID' => $uuid,
                'Content-Type' => 'application/json',
                'Consent-Confirmed' => 1,
            ];
            $birth = str_split($iin, 1);
            if ($birth[6] == 3) {
                $birthday = $birth[4] . $birth[5] . '.' . $birth[2] . $birth[3] . '.' . '19' . $birth[0] . $birth[1];
            }
            if ($birth[6] == 4) {
                $birthday = $birth[4] . $birth[5] . '.' . $birth[2] . $birth[3] . '.' . '19' . $birth[0] . $birth[1];
            }
            if ($birth[6] == 5) {
                $birthday = $birth[4] . $birth[5] . '.' . $birth[2] . $birth[3] . '.' . '20' . $birth[0] . $birth[1];
            }
            if ($birth[6] == 6) {
                $birthday = $birth[4] . $birth[5] . '.' . $birth[2] . $birth[3] . '.' . '20' . $birth[0] . $birth[1];
            }
            $body = [
                'ciin' => $iin,
                'code' => $code,
                'last_name' => $lastName,
                'first_name' => $name,
                'middle_name' => $middleName,
                'birthday' => $birthday,
            ];
            try {
                $res = $client->post($url, [
                    'headers' => $headers,
                    'body' => json_encode($body),
                ]);

                $response = $res->getBody()->getContents();
                $t = json_decode($response, true);

                $image = $t['data']['domain']['docPhoto'];
                $firstName = $t['data']['common']['docOwner']['firstName'];
                $lastName = $t['data']['common']['docOwner']['lastName'];
                $middleName = $t['data']['common']['docOwner']['middleName'];
                $docIssueDate = $t['data']['domain']['docIssuedDate'];
                $docExpirationDate = $t['data']['domain']['docExpirationDate'];
                $docNumber = $t['data']['domain']['docNumber'];
                $docGiven = $t['data']['common']['docIssuer']['nameRu'];
                $user = DB::table('user_data')->where('iin', $iin)->first();
                if ($user) {
                    DB::table('user_data')->where('iin', $iin)->update([
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'middleName' => $middleName,
                        'start' => date('Y-m-d', $docIssueDate / 1000),
                        'end' => date('Y-m-d', $docExpirationDate / 1000),
                        'docNumber' => $docNumber,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                } else {
                    DB::table('user_data')->insertGetId([
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'middleName' => $middleName,
                        'start' => date('Y-m-d', $docIssueDate / 1000),
                        'end' => date('Y-m-d', $docExpirationDate / 1000),
                        'docNumber' => $docNumber,
                        'iin' => $iin,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }
                $data = base64_decode($image);
                Storage::put($iin . '.png', $data);
                $result['name'] = $firstName;
                $result['surname'] = $lastName;
                $result['fatherName'] = $middleName;
                $result['docNumber'] = $docNumber;
                $result['docGiven'] = $docGiven;
                $result['startGiven'] = $docIssueDate;
                $result['endGiven'] = $docIssueDate;

                $result['success'] = true;
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $status = $response->getStatusCode();
                    $response = $response->getBody()->getContents();
                    $response = json_decode($response, true);
                    if (isset($response['code']) && $response['code'] == 1) {
                        $result['message'] = 'Ошибка авторизации';
                        $result['code'] = 1;
                        break;
                    }
                    if ($status == 500) {
                        $result['message'] = 'Внутренные ошибки ПКБ';
                        break;
                    }
                    if ($status == 400 && $response['code'] == 3) {
                        $result['message'] = $response['message'];
                        break;
                    }
                    if ($status == 404) {
                        $result['message'] = 'Не передан документ';
                        break;
                    }
                }
                break;
            }

        } while (false);
        return response()->json($result);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exception
     */
    public function comparePhotos(Request $request)
    {
        $photo = $request->input('photo');
        $iin = $request->input('iin');
        $leadID = $request->input('leadID');
        $fileName = $request->input('fileName');
        $extension = $request->input('extension');
        $result['success'] = false;
        do {
            if (!$photo) {
                $result['message'] = 'Не передан фото';
                break;
            }
            if (!$iin) {
                $result['message'] = 'Не передан иин';
                break;
            }
            if (!$leadID) {
                $result['message'] = 'Не передан лид';
                break;
            }

            // $fileName = $photo->getClientOriginalName();
            // $extension = $photo->getClientOriginalExtension();
            $url = 'http://178.170.221.75/biometria/storage/app/' . $iin . '.png';
            $photo2 = file_get_contents($url);
            $photo2 = base64_encode($photo2);
            $image = str_replace('data:image/jpeg;base64,', '', $photo);
            $image = str_replace(' ', '+', $image);
            $imageName = Str::random(10) . '.jpeg';
            $file = Storage::disk('local')->put($imageName, base64_decode($image));
            $mainUrl = 'https://secure2.1cb.kz/Biometry/BiometryService?wsdl';
            $xml = "
         <soapenv:Envelope xmlns:soapenv='http://schemas.xmlsoap.org/soap/envelope/' xmlns:ws='http://ws.creditinfo.com/'>
 <soapenv:Header>
   <ws:CigWsHeader
    xmlns=''
    xmlns:ns3='http://ws.creditinfo.com/'>
    <ws:Culture>ru-RU</ws:Culture>
    <ws:Password>970908350192</ws:Password>
    <ws:UserName>7471656497</ws:UserName>
    <ws:Version>2</ws:Version>
    </ws:CigWsHeader>
   </soapenv:Header>
   <soapenv:Body>
      <ws:ComparePhoto2>
         <ws:photoBody1>
         $photo
         </ws:photoBody1>
         <ws:filename1>$imageName</ws:filename1>
         <ws:format1>image/jpeg</ws:format1>
         <ws:os1>DESKTOP</ws:os1>
         <ws:photoBody2>
         $photo2
         </ws:photoBody2>
         <ws:filename2>$iin.png</ws:filename2>
         <ws:format2>image/png</ws:format2>
           <ws:os2>DESKTOP</ws:os2>
      </ws:ComparePhoto2>
   </soapenv:Body>
</soapenv:Envelope>
         ";

            $options = [
                'headers' => [
                    'Content-Type' => 'text/xml'
                ],
                'body' => $xml
            ];

            $client = new Client(['verify' => false]);
            try {
                $response = $client->request('POST', $mainUrl, $options);

                $response = $response->getBody()->getContents();
                $output = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
                $xml = new SimpleXMLElement($output);
                var_dump($xml);
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    var_dump($e->getResponse()->getStatusCode());
                    var_dump($e->getResponse()->getReasonPhrase());
                }
            }

            die();
            $similarity = $xml->SBody->ComparePhotoList->ComparePhotoResult->similarity * 100;


            DB::table('photo_data')->insertGetId([
                'iin' => $iin,
                'leadID' => $leadID,
                'selfie' => 'test',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $url = "https://icredit-crm.kz/api/docs/biometria.php?leadID=$leadID&similarity=$similarity&original=$iin.png&selfie=$file";

            $client = new Client(['verify' => false]);
            $s = $client->get($url);
            $result['success'] = true;
            $result['similarity'] = $similarity;


        } while (false);

        return response()->json($result);
    }

    public function upload(Request $request)
    {
        $photo = $request->file('photo');
        $fileName = $photo->getClientOriginalName();
        $extension = $photo->getClientOriginalExtension();
        $name = sha1($fileName) . "." . $extension;
        $s = Storage::put('selfie', $photo);

        var_dump($s);
    }

    public function susn(Request $request)
    {
        $iin = $request->input('iin');
        $url = "https://secure2.1cb.kz/susn-status/api/v1/login";
        $username = 7471656497;
        $password = 970908350192;
        $result['success'] = false;
        do {
            if (!$iin) {
                $result['message'] = 'Не передан параметры';
                break;
            }
            if (strlen($iin) != 12) {
                $result['message'] = 'Длина ИИН должен быть 12';
                break;
            }
            $http = new Client(['verify' => false]);
            $response = $http->get($url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode('7471656497:970908350192'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
            $response = $response->getBody()->getContents();
            $response = json_decode($response, true);
            $hash = $response['access']['hash'];
            var_dump($hash);
            $url = "https://secure2.1cb.kz/susn-status/api/v1/subject/$iin";
            $headers = [
                'Content-Type' => 'application/json',
                'Consent-Confirmed' => 1,
            ];
            $body = [
                'token_hash' => $hash,
            ];

            $res = $http->get($url, [
                'headers' => $headers,
                'body' => json_encode($body),
            ]);
            $res = $res->getBody()->getContents();
            print_r($res);
        } while (false);
        return response()->json($result);
    }

    public function standard()
    {
        $mainUrl = 'https://secure2.1cb.kz/backoffice';
        $xml = "
         <soapenv:Envelope xmlns:soapenv='http://schemas.xmlsoap.org/soap/envelope/' xmlns:ws='http://ws.creditinfo.com/'>
            <soapenv:Header>
                <ws:CigWsHeader>
                    <ws:Culture></ws:Culture>
                    <ws:Password>970908350192</ws:Password>
                    <ws:SecurityToken></ws:SecurityToken>
                    <ws:UserId></ws:UserId>
                    <ws:UserName>7471656497</ws:UserName>
                    <ws:Version></ws:Version>
                </ws:CigWsHeader>
            </soapenv:Header>
            <soapenv:Body>
                <ws:GetAvailableReports/>
            </soapenv:Body>
        </soapenv:Envelope>
         ";

        $options = [
            'body' => $xml
        ];

        $client = new Client(['verify' => false]);
        $response = $client->request('POST', $mainUrl, $options);
        $response = $response->getBody()->getContents();
        print_r($response);
    }

    public function comparePhotoManual(Request $request)
    {
        $photo = $request->file('photo');
        $iin = $request->input('iin');
        $leadID = $request->input('leadID');
        $fileName = $request->input('fileName');
        $extension = $request->input('extension');
        $photo2 = $request->file('photo2');
        $fileName2 = $request->input('fileName2');
        $extension2 = $request->input('extension2');
        $result['success'] = false;
        do {
            if (!$photo) {
                $result['message'] = 'Не передан фото';
                break;
            }
            if (!$iin) {
                $result['message'] = 'Не передан иин';
                break;
            }
            if (!$leadID) {
                $result['message'] = 'Не передан лид';
                break;
            }

            // $fileName = $photo->getClientOriginalName();
            // $extension = $photo->getClientOriginalExtension();
            $photo2 = base64_encode(file_get_contents($photo2->path()));
            $photo = base64_encode(file_get_contents($photo->path()));
            $mainUrl = 'https://secure2.1cb.kz/Biometry/BiometryService?wsdl';
            $xml = "
         <soapenv:Envelope xmlns:soapenv='http://schemas.xmlsoap.org/soap/envelope/' xmlns:ws='http://ws.creditinfo.com/'>
 <soapenv:Header>
   <ws:CigWsHeader
    xmlns=''
    xmlns:ns3='http://ws.creditinfo.com/'>
    <ws:Culture>ru-RU</ws:Culture>
    <ws:Password>970908350192</ws:Password>
    <ws:UserName>7471656497</ws:UserName>
    <ws:Version>2</ws:Version>
    </ws:CigWsHeader>
   </soapenv:Header>
   <soapenv:Body>
      <ws:ComparePhoto2>
         <ws:photoBody1>
         $photo
         </ws:photoBody1>
         <ws:filename1>$fileName</ws:filename1>
         <ws:format1>image/$extension</ws:format1>
         <ws:os1>DESKTOP</ws:os1>
         <ws:photoBody2>
         $photo2
         </ws:photoBody2>
         <ws:filename2>$fileName2</ws:filename2>
         <ws:format2>image/$extension2</ws:format2>
           <ws:os2>DESKTOP</ws:os2>
      </ws:ComparePhoto2>
   </soapenv:Body>
</soapenv:Envelope>
         ";

            $options = [
                'headers' => [
                    'Content-Type' => 'text/xml'
                ],
                'body' => $xml
            ];

            $client = new Client(['verify' => false]);
            $response = $client->request('POST', $mainUrl, $options);
            $response = $response->getBody()->getContents();

            $output = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
            $xml = new SimpleXMLElement($output);

            $similarity = $xml->SBody->ComparePhotoList->ComparePhotoResult->similarity * 100;


            $file = $request->file('photo');
            $s = Storage::put('selfie', $file);
            $file2 = $request->file('photo2');
            $t = Storage::put('selfie', $file2);
            DB::table('photo_data')->insertGetId([
                'iin' => $iin,
                'leadID' => $leadID,
                'selfie' => 'test',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $url = "https://icredit-crm.kz/api/docs/biometria.php?leadID=$leadID&similarity=$similarity&original=$t&selfie=$s&manual=1";

            $client = new Client(['verify' => false]);
            $s = $client->get($url);
            $result['success'] = true;
            $result['similarity'] = $similarity;


        } while (false);

        return response()->json($result);
    }

    public function checkLive(Request $request)
    {
        $photo = $request->file('photo');
        $result['success'] = false;
        do {
            if (!$photo) {
                $result['message'] = 'Не передан фото';
                break;
            }
            $fileName = $photo->getClientOriginalName();
            $extension = $photo->getClientOriginalExtension();
            $photo = base64_encode(file_get_contents($photo->path()));
            $mainUrl = 'https://secure2.1cb.kz/Biometry/BiometryService?wsdl';
            $xml = "
         <soapenv:Envelope xmlns:soapenv='http://schemas.xmlsoap.org/soap/envelope/' xmlns:ws='http://ws.creditinfo.com/'>
 <soapenv:Header>
   <ws:CigWsHeader
    xmlns=''
    xmlns:ns3='http://ws.creditinfo.com/'>
    <ws:Culture>ru-RU</ws:Culture>
    <ws:Password>970908350192</ws:Password>
    <ws:UserName>7471656497</ws:UserName>
    <ws:Version>2</ws:Version>
    </ws:CigWsHeader>
   </soapenv:Header>
   <soapenv:Body>
      <ws:LivenessRequest>
         <ws:photoBody>
         $photo
         </ws:photoBody>
         <ws:filename>123.jpg</ws:filename>
         <ws:format>image/$extension</ws:format>
         <ws:os>UNKNOWN</ws:os>
         </ws:LivenessRequest>
   </soapenv:Body>
</soapenv:Envelope>
         ";
            $options = [
                'headers' => [
                    'Content-Type' => 'text/xml'
                ],
                'body' => $xml
            ];

            $client = new Client(['verify' => false]);
            try {
                $response = $client->request('POST', $mainUrl, $options);
                print_r($response->getBody()->getContents());

            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $status = $response->getStatusCode();
                    print_r($status);
                    print_r($response->getReasonPhrase());
                }
            }
            die();
            $response = $response->getBody()->getContents();
            $output = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
            $xml = new SimpleXMLElement($output);
            print_r($xml);
        } while (false);
        return response()->json($result);
    }

    public function veriface(Request $request)
    {
        $photo = $request->input('photo');
        $iin = $request->input('iin');
        $leadID = $request->input('leadID');
        $doc = $request->input('doc');
        $result['success'] = false;

        do {
            if (!$photo) {
                $result['message'] = 'Не передан фото';
                break;
            }
            if (!$iin) {
                $result['message'] = 'Не передан иин';
                break;
            }
            if (!$leadID) {
                $result['message'] = 'Не передан номер заявки';
                break;
            }
            if (!$doc) {
                $result['message'] = 'Не передан фото уд лич';
                break;
            }
            // your base64 encoded
            $image = str_replace('data:image/jpeg;base64,', '', $photo);
            $image = str_replace(' ', '+', $image);
            $imageName = Str::random(10) . '.jpeg';
            $first = Storage::disk('local')->put($imageName, base64_decode($image));
            var_dump($imageName);
            $image = str_replace('data:image/jpeg;base64,', '', $doc);
            $image = str_replace(' ', '+', $image);
            $imageName2 = Str::random(10) . '.jpeg';
            $second = Storage::disk('local')->put($imageName2, base64_decode($image));
            var_dump($second);

            $ApiKey = "PeeKMaNIX9dNL2pB2433rs7zwrs28gGZ";
            $ApiSecret = "9ab3a51f7d5acbf20fc2a77851 6433bb";

            $timestamp = time();
            $person_id = Str::random(8);
            $host = "https://services.verigram.cloud";
            $path = "/resources/access-token?person_id=" . $person_id;
            $url = $host . $path;

            $signable_str = $timestamp . $path;

            $hmac_digest = hash_hmac('sha256', $signable_str, $ApiSecret, false);


            $headers = [
                'X-Verigram-Api-Version' => '1.1',
                'X-Verigram-Api-Key' => $ApiKey,
                'X-Verigram-Hmac-SHA256' => $hmac_digest,
                'X-Verigram-Ts' => $timestamp,
            ];
            $http = new Client(['verify' => false, 'headers' => $headers]);
            $response = $http->get($url);

            $response = $response->getBody()->getContents();
            $response = json_decode($response, true);
            $token = $response['access_token'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://services.verigram.ai:8443/s/veriface');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);


            $post = [
                'photo' => new CURLFile(Storage::path($imageName)),
                'doc' => new CURLFile(Storage::path($imageName2)),
            ];
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

            $headers = array();
            $headers[] = 'X-Verigram-Access-Token: ' . $token;
            $headers[] = 'X-Verigram-Person-Id: ' . $person_id;
            $headers[] = 'Content-Type: multipart/form-data';
            $headers[] = 'Content-Disposition: form-data';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $res = curl_exec($ch);
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            }
            curl_close($ch);
            $res = json_decode($res);
            print_r($res);
            $similarity = $res->Similarity;
            $url = "https://icredit-crm.kz/api/docs/biometria.php?leadID=$leadID&similarity=$similarity&original=$first&selfie=$second";

            $client = new Client(['verify' => false]);
            $s = $client->get($url);
            $result['success'] = true;
            $result['similarity'] = $similarity;


        } while (false);
        return response()->json($result);
    }

    public function compareTest(Request $request)
    {
        $photo = $request->file('photo');
        $iin = $request->input('iin');
        $leadID = $request->input('leadID');
        $fileName = $request->input('fileName');
        $extension = $request->input('extension');
        $result['success'] = false;
        do {
            if (!$photo) {
                $result['message'] = 'Не передан фото';
                break;
            }
            if (!$iin) {
                $result['message'] = 'Не передан иин';
                break;
            }
            if (!$leadID) {
                $result['message'] = 'Не передан лид';
                break;
            }

            // $fileName = $photo->getClientOriginalName();
            // $extension = $photo->getClientOriginalExtension();
            $url = 'http://178.170.221.75/biometria/storage/app/' . $iin . '.png';
            $photo2 = file_get_contents($url);
            $photo2 = base64_encode($photo2);
            $photo = base64_encode($photo);
            $mainUrl = 'https://secure2.1cb.kz/Biometry/BiometryService?wsdl';
            $xml = "
         <soapenv:Envelope xmlns:soapenv='http://schemas.xmlsoap.org/soap/envelope/' xmlns:ws='http://ws.creditinfo.com/'>
 <soapenv:Header>
   <ws:CigWsHeader
    xmlns=''
    xmlns:ns3='http://ws.creditinfo.com/'>
    <ws:Culture>ru-RU</ws:Culture>
    <ws:Password>970908350192</ws:Password>
    <ws:UserName>7471656497</ws:UserName>
    <ws:Version>2</ws:Version>
    </ws:CigWsHeader>
   </soapenv:Header>
   <soapenv:Body>
      <ws:ComparePhoto2>
         <ws:photoBody1>
         $photo
         </ws:photoBody1>
         <ws:filename1>$fileName</ws:filename1>
         <ws:format1>image/jpeg</ws:format1>
         <ws:os1>DESKTOP</ws:os1>
         <ws:photoBody2>
         $photo2
         </ws:photoBody2>
         <ws:filename2>$iin.png</ws:filename2>
         <ws:format2>image/png</ws:format2>
           <ws:os2>DESKTOP</ws:os2>
      </ws:ComparePhoto2>
   </soapenv:Body>
</soapenv:Envelope>
         ";

            $options = [
                'headers' => [
                    'Content-Type' => 'text/xml'
                ],
                'body' => $xml
            ];

            $client = new Client(['verify' => false]);

            $response = $client->request('POST', $mainUrl, $options);

            $response = $response->getBody()->getContents();
            $output = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
            $xml = new SimpleXMLElement($output);

            $similarity = $xml->SBody->ComparePhotoList->ComparePhotoResult->similarity * 100;


            DB::table('photo_data')->insertGetId([
                'iin' => $iin,
                'leadID' => $leadID,
                'selfie' => 'test',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $file = $request->file('photo');
            $s = Storage::put('selfie',$file);

            $url = "https://icredit-crm.kz/api/docs/biometria.php?leadID=$leadID&similarity=$similarity&original=$iin.png&selfie=$file";

            $client = new Client(['verify' => false]);
            $s = $client->get($url);
            $result['success'] = true;
            $result['similarity'] = $similarity;


        } while (false);

        return response()->json($result);
    }

    public function testing(Request $request){
        $photo = $request->file('photo');
        $s = Storage::put('selfie',$photo);
        var_dump($s);
    }
}
