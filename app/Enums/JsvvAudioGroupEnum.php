<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum JsvvAudioGroupEnum: string
{
    use EnumTrait;

    case SIREN = 'SIREN';
    case GONG = 'GONG';
    case VERBAL = 'VERBAL';
    case AUDIO = 'AUDIO';

    public function translate(): string
    {
        return match ($this) {
            self::SIREN => 'Sirény',
            self::GONG => 'Gongy',
            self::VERBAL => 'Verbální informace',
            self::AUDIO => 'Audiovstupy',
        };
    }
}
