<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class JsvvListenerService extends Service
{
    public function __construct(
        private readonly JsvvSequenceService $sequenceService = new JsvvSequenceService(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
    ) {
        parent::__construct();
    }

    public function handleFrame(array $payload): void
    {
        Log::info('JSVV frame received', $payload);

        $data = $payload['payload'] ?? $payload;
        $command = Arr::get($data, 'command');
        $params = Arr::get($data, 'params', []);
        $priority = Arr::get($data, 'priority');

        $this->sequenceService->recordEvent($data);

        if ($command === 'STOP') {
            $this->orchestrator->stop('jsvv_stop_command');
            return;
        }

        if ($command === 'SIREN_SIGNAL') {
            $signal = Arr::get($params, 'signalType') ?? Arr::get($params, 'tokens.0');
            if ($signal !== null) {
                $this->sequenceService->plan([
                    ['slot' => (int) $signal, 'category' => 'siren'],
                ], ['priority' => $priority]);
            }
        }
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'SAVED' => $this->setResponseMessage('response.saved'),
            default => $this->notSpecifiedError(),
        };
    }

}
