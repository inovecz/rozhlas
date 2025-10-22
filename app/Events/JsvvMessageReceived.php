<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\JsvvMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JsvvMessageReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public JsvvMessage $message,
        public bool $duplicate = false,
    ) {
    }
}
