<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum FileSubtypeEnum: string
{
    use EnumTrait;

    case COMMON = 'COMMON';
    case OPENING = 'OPENING';
    case CLOSING = 'CLOSING';
    case INTRO = 'INTRO';
    case OUTRO = 'OUTRO';
    case OTHER = 'OTHER';
    // JSVV Subtypes
    case SIREN = 'SIREN';
    case GONG = 'GONG';
    case VERBAL = 'VERBAL';
    case AUDIO = 'AUDIO';

    public function translate(): string
    {
        return match ($this) {
            self::COMMON => 'Běžné hlášení',
            self::OPENING => 'Úvodní slovo',
            self::CLOSING => 'Závěrečné slovo',
            self::INTRO => 'Úvodní znělka',
            self::OUTRO => 'Závěrečná znělka',
            self::OTHER => 'Ostatní',
            self::SIREN => 'Siréna',
            self::GONG => 'Gong',
            self::VERBAL => 'Verbální informace',
            self::AUDIO => 'Audiovstupy',
        };
    }
}