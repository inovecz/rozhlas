<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class JsvvSettings extends Settings
{
    public ?int $locationGroupId;
    public bool $allowSms;
    public array $smsContacts;
    public ?string $smsMessage;
    public bool $allowAlarmSms;
    public array $alarmSmsContacts;
    public ?string $alarmSmsMessage;
    public bool $allowEmail;
    public array $emailContacts;
    public ?string $emailSubject;
    public ?string $emailMessage;

    public static function group(): string
    {
        return 'jsvv';
    }
}
