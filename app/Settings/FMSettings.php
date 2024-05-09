<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FMSettings extends Settings
{
    public float $frequency;

    public static function group(): string
    {
        return 'fm';
    }
}