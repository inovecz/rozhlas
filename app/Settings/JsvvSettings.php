<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class JsvvSettings extends Settings
{
    public ?int $locationGroupId;

    public static function group(): string
    {
        return 'jsvv';
    }
}