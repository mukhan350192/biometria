<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BiometriaController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::get('/takeCode',[BiometriaController::class,'takeCode']);
Route::get('/takeDocs',[BiometriaController::class,'takeDocs']);
Route::get('/takeDocsTest',[BiometriaController::class,'takeDocsTest']);
Route::get('/test',[BiometriaController::class,'test']);
Route::post('/testing',[BiometriaController::class,'testing']);
Route::post('/comparePhotos',[BiometriaController::class,'comparePhotos']);
Route::post('/compareTest',[BiometriaController::class,'compareTest']);
Route::post('/comparePhotoManual',[BiometriaController::class,'comparePhotoManual']);
Route::post('/upload',[BiometriaController::class,'upload']);
Route::post('/upload',[BiometriaController::class,'upload']);
Route::post('/standard',[BiometriaController::class,'standard']);
Route::post('/checkLive',[BiometriaController::class,'checkLive']);
Route::post('/veriface',[BiometriaController::class,'veriface']);
