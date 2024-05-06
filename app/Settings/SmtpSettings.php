<?php

namespace App\Settings;

use App\Enums\SmtpTypeEnum;
use Spatie\LaravelSettings\Settings;

class SmtpSettings extends Settings
{
    public string $host;
    public int $port;
    public SmtpTypeEnum $encryption;
    public string $username;
    public string $password;
    public string $from_address;
    public string $from_name;

    public static function group(): string
    {
        return 'smtp';
    }
}