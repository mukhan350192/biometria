<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use GuzzleHttp\Client;
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
            if ($status == 404){
                $result['status'] = $status;
            }
            print_r($status);
            //$response = $res->getBody()->getContents();
            //$response = json_decode($response, true);
            //print_r($response);
            /*
            if (isset($response['errorCode']) && $response['errorCode'] == 404){
                $result['code'] = 1;
                $result['success'] = true;
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
            */
        } while (false);
        return response()->json($result);
    }

    public function takeDocs(Request $request)
    {
        $code = $request->input('code');
        $name = $request->input('name');
        $lastName = $request->input('lastName');
        $middleName = $request->input('middleName');
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
            $body = [
                'ciin' => $iin,
                'code' => $code,
                'last_name' => $lastName,
                'first_name' => $name,
                'middle_name' => $middleName,
                'birthday' => '08.09.1997',
            ];
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

            $result['success'] = true;
        } while (false);
        return response()->json($result);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Exception
     */
    public function comparePhotos(Request $request)
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


            $file = $request->file('photo');
            $s = Storage::put('selfie', $file);
            DB::table('photo_data')->insertGetId([
                'iin' => $iin,
                'leadID' => $leadID,
                'selfie' => 'test',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $url = "https://ic24.almait.kz/api/docs/biometria.php?leadID=$leadID&similarity=$similarity&original=$iin.png&selfie=$s";

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
}
