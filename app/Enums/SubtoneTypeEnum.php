<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum SubtoneTypeEnum: string
{
    use EnumTrait;

    case NONE = 'NONE';
    case A16 = 'A16';
    case CTCSS_38 = 'CTCSS_38';
    case CTCSS_39 = 'CTCSS_39';
    case CTCSS_47 = 'CTCSS_47';
    case CTCSS_38N = 'CTCSS_38N';
    case CTCSS_32 = 'CTCSS_32';
    case CTCSS_EIA = 'CTCSS_EIA';
    case CTCSS_ALINCO = 'CTCSS_ALINCO';
    case CTCSS_MOTOROLA = 'CTCSS_MOTOROLA';
    case DCS = 'DCS';
}
