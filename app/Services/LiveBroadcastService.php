<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use Illuminate\Http\JsonResponse;

class LiveBroadcastService extends Service
{
    private PythonClient $pythonClient;

    public function __construct(
        ?PythonClient $pythonClient = null,
    ) {
        parent::__construct();
        $this->pythonClient = $pythonClient ?? new PythonClient();
    }

    public function startBroadcast(): array|false
    {
        $config = $this->resolveLiveConfig();

        $route = $config['route'];
        if ($route === []) {
            $route = $this->normalizeIntArray(config('broadcast.default_route', []));
        }

        $zones = $config['zones'];

        $result = $this->pythonClient->startStream(
            $route !== [] ? $route : null,
            $zones !== [] ? $zones : null,
            $config['timeout'],
            $config['update_route'],
        );

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

    /**
     * @return array<string, mixed>
     */
    private function resolveLiveConfig(): array
    {
        $config = config('broadcast.live', []);
        if (!is_array($config)) {
            return [
                'route' => [],
                'zones' => [],
                'timeout' => null,
                'update_route' => false,
            ];
        }

        $config['route'] = $this->normalizeIntArray($config['route'] ?? []);
        $config['zones'] = $this->normalizeIntArray($config['zones'] ?? []);
        $config['update_route'] = $this->normalizeBool($config['update_route'] ?? false);
        $config['timeout'] = $this->normalizeTimeout($config['timeout'] ?? null);

        return $config;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeIntArray(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $normalized[] = $value;
                continue;
            }

            if (is_float($value)) {
                $normalized[] = (int) $value;
                continue;
            }

            if (is_string($value) && $value !== '' && is_numeric($value)) {
                $normalized[] = (int) $value;
            }
        }

        $result = [];
        foreach ($normalized as $number) {
            if (!in_array($number, $result, true)) {
                $result[] = $number;
            }
        }

        return $result;
    }

    private function normalizeTimeout(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function normalizeBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}
