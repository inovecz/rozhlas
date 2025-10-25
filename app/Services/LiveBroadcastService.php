<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use App\Services\Mixer\MixerController;
use App\Services\VolumeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class LiveBroadcastService extends Service
{
    private PythonClient $pythonClient;

    private MixerController $mixer;

    public function __construct(
        ?PythonClient $pythonClient = null,
        ?MixerController $mixer = null,
    ) {
        parent::__construct();
        $this->pythonClient = $pythonClient ?? new PythonClient();
        $this->mixer = $mixer ?? new MixerController();
    }

    public function startBroadcast(): array|false
    {
        $config = $this->resolveLiveConfig();

        $route = $config['route'];
        if ($route === []) {
            $route = $this->normalizeIntArray(config('broadcast.default_route', []));
        }

        $zones = $config['zones'];

        $this->applyMixerSetup($config, $route, $zones);

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
        $config = $this->resolveLiveConfig();

        $route = $config['route'];
        if ($route === []) {
            $route = $this->normalizeIntArray(config('broadcast.default_route', []));
        }

        $zones = $config['zones'];

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

        $this->mixer->reset([
            'source' => $config['source'],
            'route' => $route,
            'zones' => $zones,
        ]);

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
                'volume_groups' => [],
                'source' => null,
            ];
        }

        $config['route'] = $this->normalizeIntArray($config['route'] ?? []);
        $config['zones'] = $this->normalizeIntArray($config['zones'] ?? []);
        $config['volume_groups'] = $this->normalizeStringArray($config['volume_groups'] ?? []);
        $config['update_route'] = $this->normalizeBool($config['update_route'] ?? false);
        $config['timeout'] = $this->normalizeTimeout($config['timeout'] ?? null);

        $source = $config['source'] ?? null;
        $config['source'] = is_string($source) && $source !== '' ? $source : null;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, int> $route
     * @param array<int, int> $zones
     */
    private function applyMixerSetup(array $config, array $route, array $zones): void
    {
        $source = $config['source'];
        if (is_string($source) && $source !== '') {
            $this->mixer->activatePreset($source, [
                'route' => $route,
                'zones' => $zones,
            ]);
            $this->applySourceVolume($source);
        }

        $this->applyVolumeGroups($config['volume_groups']);
    }

    private function applySourceVolume(string $source): void
    {
        $channels = $this->resolveSourceVolumeChannels($source);
        if ($channels === []) {
            return;
        }

        $volumeManager = $this->resolveVolumeManager();
        if ($volumeManager === null) {
            return;
        }

        foreach ($channels as $channel) {
            $this->applyChannelVolume($volumeManager, $source, $channel);
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveSourceVolumeChannels(string $source): array
    {
        $mappings = [
            config('volume.source_channels', []),
            config('volume.source_output_channels', []),
        ];

        $channels = [];
        foreach ($mappings as $map) {
            if (!is_array($map)) {
                continue;
            }

            $channel = $map[$source] ?? null;
            if (is_string($channel) && $channel !== '') {
                $channels[] = $channel;
            }
        }

        $unique = [];
        foreach ($channels as $channel) {
            if (!in_array($channel, $unique, true)) {
                $unique[] = $channel;
            }
        }

        return $unique;
    }

    private function applyChannelVolume(VolumeManager $volumeManager, string $source, string $channel): void
    {
        $groupId = $this->findVolumeGroupForChannel($channel);
        if ($groupId === null) {
            return;
        }

        try {
            $value = $volumeManager->getCurrentLevel($groupId, $channel);
            $volumeManager->applyRuntimeLevel($groupId, $channel, $value);
        } catch (Throwable $exception) {
            Log::warning('Unable to apply live broadcast source volume.', [
                'source' => $source,
                'channel' => $channel,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, string> $groups
     */
    private function applyVolumeGroups(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $volumeManager = $this->resolveVolumeManager();
        if ($volumeManager === null) {
            return;
        }

        $definitions = config('volume', []);
        foreach ($groups as $groupId) {
            if (!is_string($groupId) || $groupId === '') {
                continue;
            }

            $group = $definitions[$groupId] ?? null;
            if (!is_array($group) || !isset($group['items']) || !is_array($group['items'])) {
                continue;
            }

            foreach ($group['items'] as $itemId => $_) {
                $itemKey = (string) $itemId;

                try {
                    $value = $volumeManager->getCurrentLevel($groupId, $itemKey);
                    $volumeManager->applyRuntimeLevel($groupId, $itemKey, $value);
                } catch (Throwable $exception) {
                    Log::warning('Unable to apply live broadcast volume level.', [
                        'group' => $groupId,
                        'item' => $itemKey,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    private function resolveVolumeManager(): ?VolumeManager
    {
        try {
            return app(VolumeManager::class);
        } catch (Throwable $exception) {
            Log::debug('Volume manager unavailable for live broadcast setup.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function findVolumeGroupForChannel(string $channel): ?string
    {
        $config = config('volume', []);
        foreach ($config as $groupId => $definition) {
            if (!is_array($definition) || !isset($definition['items']) || !is_array($definition['items'])) {
                continue;
            }

            if (array_key_exists($channel, $definition['items'])) {
                return (string) $groupId;
            }
        }

        return null;
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

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            if (!in_array($trimmed, $result, true)) {
                $result[] = $trimmed;
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
