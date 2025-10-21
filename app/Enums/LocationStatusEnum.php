<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Traits\EnumTrait;

enum LocationStatusEnum: string
{
    use EnumTrait;

    case OK = 'OK';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'V pořádku',
            self::WARNING => 'Varování',
            self::ERROR => 'Chyba',
            self::UNKNOWN => 'Neznámý stav',
        };
    }
}
