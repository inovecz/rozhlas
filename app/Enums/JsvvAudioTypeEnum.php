<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum JsvvAudioTypeEnum: string
{
    use EnumTrait;

    case FILE = 'FILE';
    case SOURCE = 'SOURCE';
}
