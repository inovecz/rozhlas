<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SmtpTypeEnum;
use App\Settings\SmtpSettings;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\SmtpSettingsRequest;

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
}
