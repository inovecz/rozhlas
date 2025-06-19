<?php

namespace App\Settings;

use App\Enums\TwoWayCommTypeEnum;
use Spatie\LaravelSettings\Settings;

class TwoWayCommSettings extends Settings
{
    public TwoWayCommTypeEnum $type;
    public bool $spam;
    public bool $nestStatusAutoUpdate;
    public ?string $nestFirstReadTime;
    public ?int $nestNextReadInterval;
    public bool $sensorStatusAutoUpdate;
    public ?string $sensorFirstReadTime;
    public ?int $sensorNextReadInterval;

    public static function group(): string
    {
        return 'twoWayComm';
    }
}