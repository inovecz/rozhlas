<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum TwoWayCommTypeEnum: string
{
    use EnumTrait;

    case NONE = 'NONE';
    case EIGHTSIXEIGHT = 'EIGHTSIXEIGHT';
    case EIGHTZERO = 'EIGHTZERO';
    case DIGITAL = 'DIGITAL';
}
