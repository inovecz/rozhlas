<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum MessageTypeEnum: string
{
    use EnumTrait;

    case SMS = 'SMS';
    case EMAIL = 'EMAIL';
}
