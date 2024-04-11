<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum FileTypeEnum: string
{
    use EnumTrait;

    case COMMON = 'COMMON';
    case RECORDING = 'RECORDING';
}
