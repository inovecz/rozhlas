<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\LocationController;

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
            Route::put('/rename', [FileController::class, 'renameFile']);
            Route::get('/get-blob', [FileController::class, 'getRecordWithBlob']);
        });
    });

    Route::group(['prefix' => 'schedules'], static function () {
        Route::group(['prefix' => '{schedule}', 'where' => ['schedule' => '\d+',]], static function () {
            Route::get('/', [ScheduleController::class, 'get']);
            Route::post('/', [ScheduleController::class, 'save']);
            Route::delete('/', [ScheduleController::class, 'delete']);
        });
        Route::post('/list', [ScheduleController::class, 'list']);
        Route::post('/check-time-conflict', [ScheduleController::class, 'checkTimeConflict']);
        Route::post('/', [ScheduleController::class, 'save']);
    });

    Route::group(['prefix' => 'locations'], static function () {
        Route::group(['prefix' => '{location}', 'where' => ['location' => '\d+',]], static function () {
            Route::delete('/', [LocationController::class, 'delete']);
        });
        Route::post('/list', [LocationController::class, 'list']);
        Route::post('/save', [LocationController::class, 'save']);
    });
});