<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class VolumeSettings extends Settings
{
    /**
     * @var array<string, float>
     */
    public array $inputs;

    /**
     * @var array<string, float>
     */
    public array $outputs;

    /**
     * @var array<string, float>
     */
    public array $playback;

    public static function group(): string
    {
        return 'volume';
    }
}
