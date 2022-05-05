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
    public function takeCode(Request $request){
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
            $result = $client->post($url, [
                'headers' => $headers,
                'body' => json_encode($body),
            ]);
            $status = $result->getStatusCode();
            $response = $result->getBody()->getContents();
            $response = json_decode($response, true);

            if ($status == 200 && $response['responseCode'] == 'PROFILE_DOCUMENT_ACCESS_SUCCESS'){
                $result['success'] = true;
                break;
            }
            if ($status == 400){
                $result['success'] = false;
                $result['message'] = 'Попробуйте позже';
                break;
            }
        }while(false);
        return response()->json($result);
    }
    public function takeDocs(Request $request){
        $code = $request->input('code');
        $name = $request->input('name');
        $lastName = $request->input('lastName');
        $middleName = $request->input('middleName');
        $iin = $request->input('iin');
        $result['success'] = false;
        do{
            if (!$code){
                $result['message'] = 'Не передан код';
                break;
            }
            if (!$name){
                $result['message'] = 'Не передан имя';
                break;
            }
            if (!$middleName){
                $result['message'] = 'Не передан фамилия';
                break;
            }
            $client = new Client(['verify' => false]);

            $data = DB::table('first_data')->where('iin',$iin)->first();
            $uuid = $data->requestID;
            $token = $data->token;

            $url = "https://secure2.1cb.kz/fcbid-otp/api/v1/get-pdf-document";
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'RequestID' => $uuid,
                'Content-Type' => 'application/json',
            ];
            $body = [
                'ciin' => $iin,
                'code' => $code,
                'last_name' => $lastName,
                'first_name' => $name,
                'middle_name' => $middleName,
            ];
            $res = $client->post($url, [
                'headers' => $headers,
                'body' => json_encode($body),
            ]);
            $response = $res->getBody()->getContents();
            $s = base64_decode($response);
            //var_dump($response);
            var_dump($s);
        }while(false);
        return response()->json($result);
    }
}
