<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum LocationTypeEnum: string
{
    use EnumTrait;

    case CENTRAL = 'CENTRAL';
    case NEST = 'NEST';
}
