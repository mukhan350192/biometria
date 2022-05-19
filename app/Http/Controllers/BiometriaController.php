<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function comparePhotos(Request $request){
        $photo = $request->file('photo');
        $iin = $request->input('iin');
        $result['success'] = false;
        do{
            if (!$photo){
                $result['message'] = 'Не передан фото';
                break;
            }
            if (!$iin){
                $result['message'] = 'Не передан иин';
                break;
            }
            $fileName = $photo->getClientOriginalName();
            $extension = $photo->getClientOriginalExtension();
            $url = 'http://178.170.221.75/biometria/storage/app/'.$iin.'.png';
            $photo2 = file_get_contents($url);
            $mainUrl = 'https://secure2.1cb.kz/Biometry/BiometryService?wsdl';
            $xml = "
<Envelope xmlns='http://schemas.xmlsoap.org/soap/envelope/'>
    <Body>
        <ComparePhoto2>
            <UserName>7471656497</UserName>
            <Password>970908350192</Password>
            <photoBody1>base64_encode($photo)</photoBody1>
            <filename1>$fileName</filename1>
            <format1>$extension</format1>
            <os1>UNKNOWN</os1>
            <photoBody2>base64_encode($photo2)</photoBody2>
            <filename2>$iin.'.png'</filename2>
            <format2>image/png</format2>
            <os2>UNKNOWN</os2>
        </ComparePhoto2>
    </Body>
</Envelope>
";
            $options = [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=UTF8'
                ],
                'body' => $xml
            ];

            $client = new Client(['verify'=>false]);

            $response = $client->request('POST', $mainUrl, $options);
            var_dump($response->getBody());


        }while (false);

        return response()->json($result);
    }
}
