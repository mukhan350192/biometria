<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
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
            $response = $client->get('https://test2.1cb.kz/fcbid-otp/api/v1/login', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode('7015382439:7015382439'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);
            $response = $response->getBody()->getContents();
            $response = json_decode($response, true);
            $token = $response['access']['hash'];
            $url = "https://test2.1cb.kz/fcbid-otp/api/v1/send-code";
            $uuid = Str::uuid()->toString();
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
                'form_params' => $body,
            ]);
            $response = $result->getBody()->getContents();
            var_dump($response);
        }while(false);
        return response()->json($result);
    }
    public function test(){
        echo "es";
    }
}
