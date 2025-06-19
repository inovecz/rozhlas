<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum JsvvAudioSourceEnum: string
{
    use EnumTrait;

    case INPUT_1 = 'INPUT_1';
    case INPUT_2 = 'INPUT_2';
    case INPUT_3 = 'INPUT_3';
    case INPUT_4 = 'INPUT_4';
    case INPUT_5 = 'INPUT_5';
    case INPUT_6 = 'INPUT_6';
    case INPUT_7 = 'INPUT_7';
    case INPUT_8 = 'INPUT_8';
    case FM = 'FM';
    case MIC = 'MIC';

    public function translate(): string
    {
        return match ($this) {
            self::INPUT_1 => 'Vstup 1',
            self::INPUT_2 => 'Vstup 2',
            self::INPUT_3 => 'Vstup 3',
            self::INPUT_4 => 'Vstup 4',
            self::INPUT_5 => 'Vstup 5',
            self::INPUT_6 => 'Vstup 6',
            self::INPUT_7 => 'Vstup 7',
            self::INPUT_8 => 'Vstup 8',
            self::FM => 'FM rádio',
            self::MIC => 'Mikrofon',
        };
    }
}
