<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use Illuminate\Support\Facades\Log;

class GsmStreamService extends Service
{
    public function __construct(private readonly PythonClient $pythonClient = new PythonClient())
    {
        parent::__construct();
    }

    public function handleIncomingCall(array $event): void
    {
        Log::info('GSM event received', $event);
        // TODO: implement whitelist/PIN verification and orchestration trigger.
    }
}
