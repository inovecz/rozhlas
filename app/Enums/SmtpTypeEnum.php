<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum SmtpTypeEnum: string
{
    use EnumTrait;

    case TCP = 'TCP';
    case SSL = 'SSL';
    case TLS = 'TLS';
}
