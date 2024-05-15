<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SmtpTypeEnum;
use App\Settings\FMSettings;
use App\Settings\SmtpSettings;
use App\Settings\JsvvSettings;
use Illuminate\Http\JsonResponse;
use App\Enums\TwoWayCommTypeEnum;
use App\Settings\TwoWayCommSettings;
use App\Http\Requests\FMSettingsRequest;
use App\Http\Requests\SmtpSettingsRequest;
use App\Http\Requests\JsvvSettingsRequest;
use App\Http\Requests\TwoWayCommSettingsRequest;

class SettingsController extends Controller
{
    public function getSmtpSettings(): JsonResponse
    {
        $smtpSettings = app(SmtpSettings::class);
        return $this->success([
            'host' => $smtpSettings->host,
            'port' => $smtpSettings->port,
            'encryption' => $smtpSettings->encryption->value,
            'username' => $smtpSettings->username,
            'password' => $smtpSettings->password,
            'from_address' => $smtpSettings->from_address,
            'from_name' => $smtpSettings->from_name,
        ]);
    }

    public function saveSmtpSettings(SmtpSettingsRequest $request): JsonResponse
    {
        $smtpSettings = app(SmtpSettings::class);
        $smtpSettings->host = $request->input('host');
        $smtpSettings->port = $request->input('port');
        $smtpSettings->encryption = SmtpTypeEnum::tryFrom($request->input('encryption', 'TCP')) ?? SmtpTypeEnum::TCP;
        $smtpSettings->username = $request->input('username');
        $smtpSettings->password = $request->input('password');
        $smtpSettings->from_address = $request->input('from_address');
        $smtpSettings->from_name = $request->input('from_name');
        $smtpSettings->save();
        return $this->success();
    }

    public function getFMSettings(): JsonResponse
    {
        $fmSettings = app(FMSettings::class);
        return $this->success([
            'frequency' => $fmSettings->frequency,
        ]);
    }

    public function saveFMSettings(FMSettingsRequest $request): JsonResponse
    {
        $fmSettings = app(FMSettings::class);
        $fmSettings->frequency = $request->input('frequency');
        $fmSettings->save();
        return $this->success();
    }

    public function getTwoWayCommSettings(): JsonResponse
    {
        $twoWayCommSettings = app(TwoWayCommSettings::class);
        return $this->success([
            'type' => $twoWayCommSettings->type,
            'spam' => $twoWayCommSettings->spam,
            'nestStatusAutoUpdate' => $twoWayCommSettings->nestStatusAutoUpdate,
            'nestFirstReadTime' => $twoWayCommSettings->nestFirstReadTime,
            'nestNextReadInterval' => $twoWayCommSettings->nestNextReadInterval,
            'sensorStatusAutoUpdate' => $twoWayCommSettings->sensorStatusAutoUpdate,
            'sensorFirstReadTime' => $twoWayCommSettings->sensorFirstReadTime,
            'sensorNextReadInterval' => $twoWayCommSettings->sensorNextReadInterval,
        ]);
    }

    public function saveTwoWayCommSettings(TwoWayCommSettingsRequest $request): JsonResponse
    {
        $twoWayCommSettings = app(TwoWayCommSettings::class);
        $twoWayCommSettings->type = TwoWayCommTypeEnum::tryFrom($request->input('type', 'NONE')) ?? TwoWayCommTypeEnum::NONE;
        $twoWayCommSettings->spam = $request->input('spam');
        $twoWayCommSettings->nestStatusAutoUpdate = $request->input('nestStatusAutoUpdate');
        $twoWayCommSettings->nestFirstReadTime = $request->input('nestFirstReadTime');
        $twoWayCommSettings->nestNextReadInterval = $request->input('nestNextReadInterval');
        $twoWayCommSettings->sensorStatusAutoUpdate = $request->input('sensorStatusAutoUpdate');
        $twoWayCommSettings->sensorFirstReadTime = $request->input('sensorFirstReadTime');
        $twoWayCommSettings->sensorNextReadInterval = $request->input('sensorNextReadInterval');
        $twoWayCommSettings->save();
        return $this->success();
    }

    public function getJsvvSettings(): JsonResponse
    {
        $jsvvSettings = app(JsvvSettings::class);
        return $this->success([
            'locationGroupId' => $jsvvSettings->locationGroupId,
        ]);
    }

    public function saveJsvvSettings(JsvvSettingsRequest $request): JsonResponse
    {
        $jsvvSettings = app(JsvvSettings::class);
        $jsvvSettings->locationGroupId = $request->input('locationGroupId');
        $jsvvSettings->save();
        return $this->success();
    }
}
