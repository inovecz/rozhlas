<?php

declare(strict_types=1);

namespace App\Services\RF;

use App\Enums\ModbusRegister;
use App\Libraries\PythonClient;
use App\Services\RF\Driver\DriverRs485Gpio;
use App\Services\RF\Driver\DriverRs485Rts;
use App\Services\RF\Driver\NullDriver;
use App\Services\RF\Driver\Rs485DriverInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class RfBus
{
    private Rs485DriverInterface $driver;
    private PythonClient $client;
    private CacheManager $cache;
    private string $lockKey;
    private int $lockTtl;
    private int $lockWait;
    private int $unitId;
    private int $txStartValue;
    private int $txStopValue;
    private int $rxStopValue;
    private int $rxPlayLastValue;
    private string $priorityStateKey;
    private string $priorityLockKey;
    /** @var array<string, int> */
    private array $priorityLevels;
    /** @var array<string, string> */
    private array $priorityAliases;
    private string $priorityDefault;
    private float $priorityTimeout;
    private float $priorityRetryDelay;
    private float $priorityStaleAfter;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(
        ?PythonClient $client = null,
        ?CacheManager $cache = null,
        ?array $config = null,
    ) {
        $this->client = $client ?? new PythonClient();
        $this->cache = $cache ?? app('cache');

        $config = $config ?? config('rf', []);

        $this->lockKey = (string) ($config['lock_key'] ?? 'rf:bus');
        $this->lockTtl = (int) ($config['lock_ttl'] ?? 5);
        $this->lockWait = (int) ($config['lock_wait'] ?? 2);
        $this->unitId = (int) ($config['unit_id'] ?? config('modbus.unit_id', 1));

        $txValues = Arr::get($config, 'tx_control', []);
        $this->txStartValue = (int) Arr::get($txValues, 'start', 2);
        $this->txStopValue = (int) Arr::get($txValues, 'stop', 1);

        $rxValues = Arr::get($config, 'rx_control', []);
        $this->rxStopValue = (int) Arr::get($rxValues, 'stop', 1);
        $this->rxPlayLastValue = (int) Arr::get($rxValues, 'play_last', 3);

        $priorityConfig = Arr::get($config, 'priority', []);
        $this->priorityStateKey = (string) ($priorityConfig['state_key'] ?? 'rf:bus:priority');
        $this->priorityLockKey = (string) ($priorityConfig['lock_key'] ?? 'rf:bus:priority:lock');
        $this->priorityDefault = strtolower((string) ($priorityConfig['default'] ?? 'plan'));
        $this->priorityTimeout = (float) ($priorityConfig['timeout'] ?? 5.0);
        $this->priorityRetryDelay = max(0.001, (float) ($priorityConfig['retry_delay'] ?? 0.1));
        $this->priorityStaleAfter = max(0.1, (float) ($priorityConfig['stale_after'] ?? 5.0));

        $levelsConfig = Arr::get($priorityConfig, 'levels', null);
        if (!is_array($levelsConfig) || $levelsConfig === []) {
            $levelsConfig = [
                'stop' => 0,
                'jsvv' => 10,
                'gsm' => 20,
                'plan' => 30,
                'polling' => 40,
            ];
        }

        $mappedLevels = [];
        foreach ($levelsConfig as $name => $levelValue) {
            $mappedLevels[strtolower((string) $name)] = (int) $levelValue;
        }
        if (!array_key_exists($this->priorityDefault, $mappedLevels)) {
            $mappedLevels[$this->priorityDefault] = min($mappedLevels);
        }
        $this->priorityLevels = $mappedLevels;

        $aliasesConfig = Arr::get($priorityConfig, 'aliases', []);
        if (!is_array($aliasesConfig)) {
            $aliasesConfig = [];
        }

        $defaultAliases = [
            'stop' => 'stop',
            'emergency_stop' => 'stop',
            'p0' => 'stop',
            'jsvv_stop' => 'stop',
            'abort' => 'stop',
            'jsvv' => 'jsvv',
            'p1' => 'jsvv',
            'p2' => 'jsvv',
            'p3' => 'jsvv',
            'gsm' => 'gsm',
            'incoming_call' => 'gsm',
            'plan' => 'plan',
            'schedule' => 'plan',
            'poll' => 'polling',
            'polling' => 'polling',
            'alarm' => 'polling',
            'status' => 'polling',
        ];

        $aliasMap = [];
        foreach (array_merge($defaultAliases, $aliasesConfig) as $alias => $target) {
            $aliasKey = strtolower((string) $alias);
            if ($aliasKey === '') {
                continue;
            }

            $targetKey = strtolower((string) $target);
            if ($targetKey === '' || !array_key_exists($targetKey, $this->priorityLevels)) {
                $targetKey = $this->priorityDefault;
            }

            $aliasMap[$aliasKey] = $targetKey;
        }

        foreach (array_keys($this->priorityLevels) as $name) {
            $aliasMap[$name] = $name;
        }

        $this->priorityAliases = $aliasMap;

        $this->driver = $this->createDriver(Arr::get($config, 'rs485', []));
    }

    public function txStart(?string $priority = null): void
    {
        $selectedPriority = $priority !== null ? $priority : 'plan';

        $this->pushRequest($selectedPriority, function (): void {
            $this->withLock(function (): void {
                $this->driver->enterTransmit();
                $this->client->writeTxControl($this->txStartValue, $this->unitId);
            });
        });
    }

    public function txStop(?string $priority = null): void
    {
        $selectedPriority = $priority !== null ? $priority : 'stop';

        $this->pushRequest($selectedPriority, function (): void {
            $this->withLock(function (): void {
                $this->client->writeTxControl($this->txStopValue, $this->unitId);
                $this->driver->enterReceive();
            });
        });
    }

    public function rxPlayLast(?string $priority = null): void
    {
        $selectedPriority = $priority !== null ? $priority : 'polling';

        $this->pushRequest($selectedPriority, function (): void {
            $this->withLock(function (): void {
                $this->driver->enterReceive();
                $this->client->writeRxControl($this->rxPlayLastValue, $this->unitId);
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function readStatus(?string $priority = null): array
    {
        $selectedPriority = $priority !== null ? $priority : 'polling';

        return $this->pushRequest($selectedPriority, function (): array {
            return $this->withLock(function (): array {
                $tx = $this->client->readTxControl($this->unitId);
                $rx = $this->client->readRxControl($this->unitId);
                $status = $this->client->readStatusRegister($this->unitId);
                $error = $this->client->readErrorRegister($this->unitId);
                $frequency = $this->client->readRegisterValue(ModbusRegister::FREQUENCY, $this->unitId);

                return [
                    'tx_control' => $tx,
                    'rx_control' => $rx,
                    'status' => $status,
                    'error' => $error,
                    'frequency_hz' => $frequency,
                ];
            });
        });
    }

    /**
     * Read alarm buffer words (0x3000-0x3009) in LIFO order.
     *
     * @param int|null $limit
     * @param string|null $priority
     * @return array<string, mixed>
     */
    public function readBuffersLifo(?int $limit = null, ?string $priority = null): array
    {
        $selectedPriority = $priority !== null ? $priority : 'polling';

        return $this->pushRequest($selectedPriority, function () use ($limit): array {
            return $this->withLock(function () use ($limit): array {
                $address = $this->client->readRegisterValue(ModbusRegister::ALARM_ADDRESS, $this->unitId);
                $repeat = $this->client->readRegisterValue(ModbusRegister::ALARM_REPEAT, $this->unitId);
                $bufferWords = $this->client->readRegisterValues(ModbusRegister::ALARM_DATA, $this->unitId) ?? [];

                $stack = array_values(array_filter(array_reverse($bufferWords), static fn ($word) => $word !== 0));
                if ($limit !== null) {
                    $stack = array_slice($stack, 0, $limit);
                }

                return [
                    'source_address' => $address,
                    'repeat' => $repeat,
                    'frames' => $stack,
                    'raw' => $bufferWords,
                ];
            });
        });
    }

    public function __destruct()
    {
        $this->driver->shutdown();
    }

    private function withLock(callable $callback)
    {
        $lock = $this->cache->lock($this->lockKey, $this->lockTtl);

        try {
            return $lock->block($this->lockWait, static fn () => $callback());
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to obtain RF bus lock: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @template TReturn
     * @param callable():TReturn $callback
     * @return TReturn
     */
    public function pushRequest(string $priority, callable $callback)
    {
        $normalizedPriority = $this->normalizePriorityName($priority);
        $level = $this->resolvePriorityLevel($normalizedPriority);
        $deadline = microtime(true) + max(0.1, $this->priorityTimeout);
        $token = (string) Str::uuid();

        while (true) {
            $now = microtime(true);
            $granted = false;
            $lock = $this->cache->lock($this->priorityLockKey, $this->lockTtl);

            try {
                $granted = (bool) $lock->block($this->lockWait, function () use ($token, $normalizedPriority, $level, $now): bool {
                    $state = $this->normalizePriorityState($this->cache->get($this->priorityStateKey));
                    $queue = $this->cleanPriorityQueue($state['queue'], $now);

                    $found = false;
                    foreach ($queue as $index => $entry) {
                        if (($entry['token'] ?? null) === $token) {
                            $queue[$index]['expires_at'] = $now + max(1.0, $this->priorityStaleAfter);
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $queue[] = [
                            'token' => $token,
                            'priority' => $normalizedPriority,
                            'level' => $level,
                            'enqueued_at' => $now,
                            'expires_at' => $now + max(1.0, $this->priorityStaleAfter),
                        ];
                    }

                    usort($queue, fn (array $a, array $b): int => $this->comparePriorityEntries($a, $b));

                    $state['queue'] = $queue;
                    $this->storePriorityState($state);

                    return $queue !== [] && ($queue[0]['token'] ?? null) === $token;
                });
            } catch (LockTimeoutException $exception) {
                Log::debug('RF priority queue lock contention, retrying', [
                    'priority' => $normalizedPriority,
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($granted) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $this->cleanupPriorityToken($token);
                throw new RuntimeException('RF bus is busy handling a higher-priority request.');
            }

            usleep((int) max(1, $this->priorityRetryDelay * 1_000_000));
        }

        try {
            /** @var TReturn $result */
            $result = $callback();
            return $result;
        } finally {
            $this->cleanupPriorityToken($token);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createDriver(array $config): Rs485DriverInterface
    {
        $mode = strtolower((string) ($config['mode'] ?? 'none'));

        return match ($mode) {
            'gpio' => new DriverRs485Gpio(Arr::get($config, 'gpio', [])),
            'rts' => new DriverRs485Rts(Arr::get($config, 'rts', [])),
            default => new NullDriver(['mode' => $mode]),
        };
    }

    private function normalizePriorityName(string $priority): string
    {
        $normalized = strtolower(trim($priority));
        if ($normalized === '') {
            return $this->priorityDefault;
        }

        if (isset($this->priorityAliases[$normalized])) {
            return $this->priorityAliases[$normalized];
        }

        return array_key_exists($normalized, $this->priorityLevels)
            ? $normalized
            : $this->priorityDefault;
    }

    private function resolvePriorityLevel(string $priority): int
    {
        $normalized = $this->normalizePriorityName($priority);

        if (isset($this->priorityLevels[$normalized])) {
            return $this->priorityLevels[$normalized];
        }

        return $this->priorityLevels[$this->priorityDefault] ?? min($this->priorityLevels);
    }

    /**
     * @param array<int, array<string, mixed>> $queue
     * @return array<int, array<string, mixed>>
     */
    private function cleanPriorityQueue(array $queue, float $now): array
    {
        $clean = [];
        $seen = [];

        foreach ($queue as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $token = (string) ($entry['token'] ?? '');
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $expiresAt = isset($entry['expires_at']) ? (float) $entry['expires_at'] : 0.0;
            if ($expiresAt > 0.0 && $expiresAt < $now) {
                continue;
            }
            if ($expiresAt <= 0.0) {
                $expiresAt = $now + max(1.0, $this->priorityStaleAfter);
            }

            $priorityName = $this->normalizePriorityName((string) ($entry['priority'] ?? ''));
            $level = $this->resolvePriorityLevel($priorityName);
            $enqueuedAt = isset($entry['enqueued_at']) ? (float) $entry['enqueued_at'] : $now;

            $clean[] = [
                'token' => $token,
                'priority' => $priorityName,
                'level' => $level,
                'enqueued_at' => $enqueuedAt,
                'expires_at' => $expiresAt,
            ];

            $seen[$token] = true;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{queue: array<int, array<string, mixed>>}
     */
    private function normalizePriorityState(mixed $state): array
    {
        if (!is_array($state)) {
            return ['queue' => []];
        }

        if (isset($state['queue']) && is_array($state['queue'])) {
            return ['queue' => array_values(array_filter($state['queue'], static fn ($entry) => is_array($entry)))];
        }

        if (isset($state['token'])) {
            return ['queue' => [$state]];
        }

        return ['queue' => []];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function comparePriorityEntries(array $a, array $b): int
    {
        $levelA = (int) ($a['level'] ?? PHP_INT_MAX);
        $levelB = (int) ($b['level'] ?? PHP_INT_MAX);

        if ($levelA !== $levelB) {
            return $levelA <=> $levelB;
        }

        $timeA = (float) ($a['enqueued_at'] ?? 0.0);
        $timeB = (float) ($b['enqueued_at'] ?? 0.0);

        return $timeA <=> $timeB;
    }

    private function cleanupPriorityToken(string $token): void
    {
        $lock = $this->cache->lock($this->priorityLockKey, $this->lockTtl);

        try {
            $lock->block($this->lockWait, function () use ($token): void {
                $state = $this->normalizePriorityState($this->cache->get($this->priorityStateKey));
                $now = microtime(true);
                $queue = $this->cleanPriorityQueue($state['queue'], $now);

                $queue = array_values(array_filter($queue, fn ($entry) => ($entry['token'] ?? null) !== $token));

                if ($queue === []) {
                    $this->cache->forget($this->priorityStateKey);
                    return;
                }

                usort($queue, fn (array $a, array $b): int => $this->comparePriorityEntries($a, $b));

                $state['queue'] = $queue;
                $this->storePriorityState($state);
            });
        } catch (LockTimeoutException $exception) {
            Log::debug('RF priority queue cleanup skipped (lock contention)', [
                'token' => $token,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function storePriorityState(array $state): void
    {
        $queue = array_values($state['queue'] ?? []);
        if ($queue === []) {
            $this->cache->forget($this->priorityStateKey);
            return;
        }

        $state['queue'] = $queue;

        if ($this->priorityStaleAfter > 0) {
            $now = microtime(true);
            $maxExpires = $now;

            foreach ($queue as $entry) {
                $expiresAt = isset($entry['expires_at']) ? (float) $entry['expires_at'] : $now;
                if ($expiresAt > $maxExpires) {
                    $maxExpires = $expiresAt;
                }
            }

            $ttl = max(1, (int) ceil($maxExpires - $now));
            $this->cache->put($this->priorityStateKey, $state, $ttl);
            return;
        }

        $this->cache->forever($this->priorityStateKey, $state);
    }
}
