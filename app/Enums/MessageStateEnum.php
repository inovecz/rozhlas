<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum MessageStateEnum: string
{
    use EnumTrait;

    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case RECEIVED = 'RECEIVED';
}
