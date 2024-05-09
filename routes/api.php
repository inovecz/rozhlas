<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SettingsController;

Route::group(['prefix' => 'auth', 'middleware' => ['api', 'auth:api']], static function () {
    Route::post('/login', [AuthController::class, 'login'])->withoutMiddleware(['auth:api']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/me', [AuthController::class, 'me']);
});

Route::group(['middleware' => ['api']], static function () {
    Route::group(['prefix' => 'contacts'], static function () {
        Route::group(['prefix' => '{contact}', 'where' => ['contact' => '\d+',]], static function () {
            Route::post('/', [ContactController::class, 'saveContact']);
            Route::delete('/', [ContactController::class, 'deleteContact']);
        });
        Route::group(['prefix' => 'groups'], static function () {
            Route::group(['prefix' => '{contactGroup}', 'where' => ['contactGroup' => '\d+',]], static function () {
                Route::post('/', [ContactController::class, 'saveContactGroup']);
                Route::delete('/', [ContactController::class, 'deleteContactGroup']);
            });
            Route::get('/', [ContactController::class, 'getAllGroups']);
            Route::post('/', [ContactController::class, 'saveContactGroup']);
            Route::post('list', [ContactController::class, 'listGroups']);
        });
        Route::post('/', [ContactController::class, 'saveContact']);
        Route::post('list', [ContactController::class, 'list']);
    });

    Route::group(['prefix' => 'locations'], static function () {
        Route::group(['prefix' => '{location}', 'where' => ['location' => '\d+',]], static function () {
            Route::delete('/', [LocationController::class, 'delete']);
        });
        Route::post('/list', [LocationController::class, 'list']);
        Route::post('/save', [LocationController::class, 'save']);
    });

    Route::group(['prefix' => 'logs'], static function () {
        Route::post('/list', [LogController::class, 'list']);
    });

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
        Route::post('/', [ScheduleController::class, 'save']);
        Route::post('/list', [ScheduleController::class, 'list']);
        Route::post('/check-time-conflict', [ScheduleController::class, 'checkTimeConflict']);
    });

    Route::group(['prefix' => 'settings'], static function () {
        Route::group(['prefix' => 'smtp'], static function () {
            Route::post('/', [SettingsController::class, 'saveSmtpSettings']);
            Route::get('/', [SettingsController::class, 'getSmtpSettings']);
        });
        Route::group(['prefix' => 'fm'], static function () {
            Route::post('/', [SettingsController::class, 'saveFmSettings']);
            Route::get('/', [SettingsController::class, 'getFmSettings']);
        });
    });

    Route::post('/upload', [FileController::class, 'upload']);

    Route::group(['prefix' => 'users'], static function () {
        Route::group(['prefix' => '{user}', 'where' => ['user' => '\d+',]], static function () {
            Route::post('/', [UserController::class, 'save']);
            Route::delete('/', [UserController::class, 'delete']);
        });
        Route::post('/', [UserController::class, 'save']);
        Route::post('/list', [UserController::class, 'list']);
    });
});