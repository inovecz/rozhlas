<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\JsvvAlarmController;
use App\Http\Controllers\LiveBroadcastController;
use App\Http\Controllers\Api\FmController;
use App\Http\Controllers\Api\ControlTabController;
use App\Http\Controllers\Api\GsmController;
use App\Http\Controllers\Api\JsvvEventController;
use App\Http\Controllers\Api\JsvvSequenceController;
use App\Http\Controllers\Api\LiveBroadcastApiController;
use App\Http\Controllers\Api\ManualControlController;
use App\Http\Controllers\Api\TelemetryController;

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
            Route::post('/list', [ContactController::class, 'listGroups']);
        });
        Route::get('/', [ContactController::class, 'getAllContacts']);
        Route::post('/', [ContactController::class, 'saveContact']);
        Route::post('/list', [ContactController::class, 'list']);
    });

    Route::group(['prefix' => 'jsvv-alarms'], static function () {
        Route::group(['prefix' => '{jsvvAlarm}', 'where' => ['jsvvAlarm' => '\d+',]], static function () {
            Route::get('/', [JsvvAlarmController::class, 'getJsvvAlarm']);
            Route::post('/', [JsvvAlarmController::class, 'saveJsvvAlarm']);
        });
        Route::post('/', [JsvvAlarmController::class, 'saveJsvvAlarm']);
        Route::get('/all', [JsvvAlarmController::class, 'getAll']);
        Route::get('/audios', [JsvvAlarmController::class, 'getAudios']);
        Route::post('/audios', [JsvvAlarmController::class, 'saveAudios']);
    });

    Route::group(['prefix' => 'live-broadcast'], static function () {
        Route::get('/start', [LiveBroadcastController::class, 'start']);
        Route::get('/stop', [LiveBroadcastController::class, 'stop']);

        Route::post('/start', [LiveBroadcastApiController::class, 'start']);
        Route::post('/stop', [LiveBroadcastApiController::class, 'stop']);
        Route::get('/status', [LiveBroadcastApiController::class, 'status']);
        Route::post('/playlist', [LiveBroadcastApiController::class, 'playlist']);
        Route::post('/playlist/{playlistId}/cancel', [LiveBroadcastApiController::class, 'cancelPlaylist']);
        Route::get('/sources', [LiveBroadcastApiController::class, 'sources']);
        Route::get('/volume', [LiveBroadcastApiController::class, 'getVolumeLevels']);
        Route::post('/volume', [LiveBroadcastApiController::class, 'updateVolumeLevel']);
        Route::post('/volume/runtime', [LiveBroadcastApiController::class, 'applyRuntimeVolumeLevel']);
    });

    Route::group(['prefix' => 'jsvv'], static function () {
        Route::post('/sequences', [JsvvSequenceController::class, 'plan']);
        Route::post('/sequences/{sequenceId}/trigger', [JsvvSequenceController::class, 'trigger']);
        Route::get('/assets', [JsvvSequenceController::class, 'assets']);
        Route::post('/events', JsvvEventController::class);
    });

    Route::group(['prefix' => 'gsm'], static function () {
        Route::post('/events', [GsmController::class, 'events']);
        Route::get('/whitelist', [GsmController::class, 'index']);
        Route::post('/whitelist', [GsmController::class, 'store']);
        Route::put('/whitelist/{id}', [GsmController::class, 'update']);
        Route::delete('/whitelist/{id}', [GsmController::class, 'destroy']);
        Route::post('/pin/verify', [GsmController::class, 'verifyPin']);
    });
    Route::post('/control-tab/events', [ControlTabController::class, 'handle']);

    Route::group(['prefix' => 'fm'], static function () {
        Route::get('/frequency', [FmController::class, 'show']);
        Route::post('/frequency', [FmController::class, 'update']);
    });

    Route::post('/manual-control/events', ManualControlController::class);
    Route::get('/stream/telemetry', TelemetryController::class);

    Route::group(['prefix' => 'locations'], static function () {
        Route::group(['prefix' => '{location}', 'where' => ['location' => '\d+',]], static function () {
            Route::delete('/', [LocationController::class, 'deleteLocation']);
        });
        Route::group(['prefix' => 'groups'], static function () {
            Route::group(['prefix' => '{locationGroup}', 'where' => ['locationGroup' => '\d+',]], static function () {
                Route::get('/', [LocationController::class, 'getLocationGroup']);
                Route::post('/', [LocationController::class, 'saveLocationGroup']);
                Route::delete('/', [LocationController::class, 'deleteLocationGroup']);
            });
            Route::get('/', [LocationController::class, 'getAllGroups']);
            Route::post('/', [LocationController::class, 'saveLocationGroup']);
            Route::post('/list', [LocationController::class, 'listGroups']);
        });
        Route::post('/list', [LocationController::class, 'list']);
        Route::post('/save', [LocationController::class, 'saveLocation']);
    });

    Route::group(['prefix' => 'logs'], static function () {
        Route::post('/list', [LogController::class, 'list']);
    });

    Route::group(['prefix' => 'messages'], static function () {
        Route::post('/list', [MessageController::class, 'list']);
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
            Route::post('/', [SettingsController::class, 'saveFMSettings']);
            Route::get('/', [SettingsController::class, 'getFMSettings']);
        });
        Route::group(['prefix' => 'two-way-comm'], static function () {
            Route::post('/', [SettingsController::class, 'saveTwoWayCommSettings']);
            Route::get('/', [SettingsController::class, 'getTwoWayCommSettings']);
        });
        Route::group(['prefix' => 'jsvv'], static function () {
            Route::post('/', [SettingsController::class, 'saveJsvvSettings']);
            Route::get('/', [SettingsController::class, 'getJsvvSettings']);
        });
        Route::group(['prefix' => 'volume'], static function () {
            Route::get('/', [SettingsController::class, 'getVolumeSettings']);
            Route::post('/', [SettingsController::class, 'saveVolumeSettings']);
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
