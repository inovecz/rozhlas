<?php

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum FileTypeEnum: string
{
    use EnumTrait;

    case COMMON = 'COMMON';
    case RECORD = 'RECORD';
}
