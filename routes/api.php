<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;

Route::group(['prefix' => 'auth', 'middleware' => ['api', 'auth:api']], static function () {
    Route::post('/login', [AuthController::class, 'login'])->withoutMiddleware(['auth:api']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/me', [AuthController::class, 'me']);
});

Route::group(['middleware' => ['api']], static function () {
    Route::post('/upload', [FileController::class, 'upload']);

    Route::group(['prefix' => 'records'], static function () {
        Route::post('/list', [FileController::class, 'list']);
        Route::group(['prefix' => '{file}', 'where' => ['file' => '\d+']], static function () {
            Route::delete('/', [FileController::class, 'delete']);
            Route::get('/get-blob', [FileController::class, 'getRecordWithBlob']);
        });
    });
});