<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group(['prefix' => 'auth'], static function () {
    Route::group(['middleware' => 'guest'], static function () {
        Route::post('/login', [AuthController::class, 'login']);
    });
    Route::post('/logout', [AuthController::class, 'logout']);
});