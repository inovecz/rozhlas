<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use Illuminate\Http\JsonResponse;

class LiveBroadcastService extends Service
{
    private PythonClient $pythonClient;

    public function __construct(?PythonClient $pythonClient = null)
    {
        parent::__construct();
        $this->pythonClient = $pythonClient ?? new PythonClient();
    }

    public function startBroadcast(): array|false
    {
        $result = $this->pythonClient->startStream();

        if (!$result['success']) {
            $this->setStatus('NOK', 'live_broadcast.start_failed', 500, [
                'exitCode' => $result['exitCode'],
                'stderr' => $result['stderr'],
                'stdout' => $result['stdout'],
                'json' => $result['json'],
            ]);

            return false;
        }

        $payload = $this->buildSuccessPayload($result);

        $this->setStatus('OK', 'live_broadcast.started', 200, [
            'payload' => $payload,
        ]);

        return $payload;
    }

    public function stopBroadcast(): array|false
    {
        $result = $this->pythonClient->stopStream();

        if (!$result['success']) {
            $this->setStatus('NOK', 'live_broadcast.stop_failed', 500, [
                'exitCode' => $result['exitCode'],
                'stderr' => $result['stderr'],
                'stdout' => $result['stdout'],
                'json' => $result['json'],
            ]);

            return false;
        }

        $payload = $this->buildSuccessPayload($result);

        $this->setStatus('OK', 'live_broadcast.stopped', 200, [
            'payload' => $payload,
        ]);

        return $payload;
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'OK' => $this->setResponseMessage('response.ok'),
            'NOK' => $this->setResponseMessage('response.nok', 400),
            default => $this->notSpecifiedError(),
        };
    }

    /**
     * Normalize Python client output to a consistent array payload.
     *
     * @param array{json: mixed, stdout: array<int, string>} $result
     */
    private function buildSuccessPayload(array $result): array
    {
        if (isset($result['json']) && is_array($result['json'])) {
            if (isset($result['json']['data']) && is_array($result['json']['data'])) {
                return $result['json']['data'];
            }

            return $result['json'];
        }

        if (!empty($result['stdout'])) {
            return ['stdout' => $result['stdout']];
        }

        return ['stdout' => []];
    }
}
