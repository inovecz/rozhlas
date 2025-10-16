<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use Illuminate\Support\Facades\Log;

class JsvvListenerService extends Service
{
    public function __construct(private readonly PythonClient $client = new PythonClient())
    {
        parent::__construct();
    }

    public function handleFrame(array $payload): void
    {
        Log::info('JSVV frame received', $payload);
        // TODO: implement priority orchestration based on payload contents.
    }
}
