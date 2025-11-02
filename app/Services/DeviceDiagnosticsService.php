<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use App\Models\DeviceHealthMetric;
use App\Services\RF\RfBus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeviceDiagnosticsService extends Service
{
    private const CACHE_KEY = 'device_diagnostics:latest_snapshot';
    private const CACHE_TTL_SECONDS = 5;

    /**
     * @var array<string, array<string, mixed>>
     */
    private const METRIC_DEFINITIONS = [
        'cabinet_door' => [
            'label' => 'Skříň elektroniky',
            'bit' => 0,
            'ok_value' => 1,
            'fault_code' => 101,
            'fault_detail' => 'CABINET_OPEN',
        ],
        'battery_backup' => [
            'label' => 'Záložní akumulátor',
            'bit' => 1,
            'ok_value' => 1,
            'fault_code' => 102,
            'fault_detail' => 'BATTERY_LOW',
        ],
        'mains_power' => [
            'label' => 'Napájení z distribuční sítě',
            'bit' => 2,
            'ok_value' => 1,
            'fault_code' => 103,
            'fault_detail' => 'MAINS_FAIL',
        ],
        'audio_path' => [
            'label' => 'Audio cesta / reproduktory',
            'bit' => 3,
            'ok_value' => 1,
            'fault_code' => 104,
            'fault_detail' => 'AUDIO_PATH_FAULT',
        ],
    ];

    public function __construct(
        private readonly RfBus $rfBus = new RfBus(),
        private readonly PythonClient $python = new PythonClient(),
    ) {
        parent::__construct();
    }

    /**
     * Collect latest Modbus diagnostics, persist them and optionally emit KPPS fault notifications.
     *
     * @param bool $triggerNotifications Whether to send KPPS FAULT frames for newly detected issues.
     * @return array<string, mixed>
     */
    public function collect(bool $triggerNotifications = true): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['timestamp'])) {
            return $cached;
        }

        $timestamp = CarbonImmutable::now();
        $statusSnapshot = null;
        $errorMessage = null;

        try {
            $statusSnapshot = $this->rfBus->readStatus('polling');
        } catch (\Throwable $exception) {
            $errorMessage = $exception->getMessage();
            Log::warning('Unable to read device diagnostics from RF bus.', [
                'error' => $errorMessage,
            ]);
        }

        $decoded = $statusSnapshot !== null
            ? $this->decodeStatusSnapshot($statusSnapshot)
            : $this->buildUnknownMetrics('hardware_unreachable', $errorMessage);

        $metrics = $this->ensureAllMetrics($decoded['metrics']);
        $meta = $decoded['meta'] ?? [];
        $faults = array_values(array_filter($metrics, static fn (array $metric): bool => ($metric['state'] ?? null) === 'fault'));

        $snapshot = [
            'timestamp' => $timestamp->toIso8601String(),
            'metrics' => $metrics,
            'faults' => $faults,
            'raw' => $statusSnapshot,
            'error' => $errorMessage,
            'meta' => $meta,
        ];

        Cache::put(self::CACHE_KEY, $snapshot, now()->addSeconds(self::CACHE_TTL_SECONDS));

        $this->persistMetrics($metrics, $triggerNotifications && $errorMessage === null);

        return $snapshot;
    }

    /**
     * Return persisted diagnostics state without triggering Modbus request.
     */
    public function getStoredMetrics(): array
    {
        $records = DeviceHealthMetric::query()
            ->orderBy('metric')
            ->get();

        $metrics = [];
        foreach (self::METRIC_DEFINITIONS as $metric => $definition) {
            $record = $records->firstWhere('metric', $metric);

            $metrics[$metric] = [
                'metric' => $metric,
                'label' => $definition['label'],
                'state' => $record?->state ?? 'unknown',
                'value' => Arr::get($record?->meta ?? [], 'value'),
                'bit' => $definition['bit'],
                'updated_at' => $record?->updated_at?->toIso8601String(),
                'fault_notified_at' => $record?->last_fault_notified_at?->toIso8601String(),
            ];
        }

        return $metrics;
    }

    /**
     * Provide a consolidated overview suitable for API responses.
     */
    public function overview(bool $refresh = false, bool $triggerNotifications = false): array
    {
        $snapshot = $refresh ? $this->collect($triggerNotifications) : (Cache::get(self::CACHE_KEY) ?: $this->collect($triggerNotifications));

        return [
            'timestamp' => $snapshot['timestamp'],
            'metrics' => $this->getStoredMetrics(),
            'faults' => $snapshot['faults'],
            'raw' => $snapshot['raw'],
            'error' => $snapshot['error'],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function decodeStatusSnapshot(array $snapshot): array
    {
        $statusValue = (int) Arr::get($snapshot, 'status', 0);
        $txControl = (int) Arr::get($snapshot, 'tx_control', 0);
        $errorValue = (int) Arr::get($snapshot, 'error', 0);

        $metrics = [];
        foreach (self::METRIC_DEFINITIONS as $metric => $definition) {
            $bit = (int) $definition['bit'];
            $okValue = (int) $definition['ok_value'];
            $bitSet = (($statusValue >> $bit) & 0x01) === 1;
            $isOk = (int) $bitSet === $okValue;

            $metrics[$metric] = [
                'metric' => $metric,
                'label' => $definition['label'],
                'state' => $isOk ? 'ok' : 'fault',
                'value' => $bitSet,
                'bit' => $bit,
            ];
        }

        return [
            'metrics' => $metrics,
            'meta' => [
                'status_register' => $statusValue,
                'tx_control' => $txControl,
                'error_register' => $errorValue,
            ],
        ];
    }

    /**
     * @param string|null $errorCode
     * @param string|null $details
     * @return array<string, mixed>
     */
    private function buildUnknownMetrics(?string $errorCode, ?string $details): array
    {
        $metrics = [];
        foreach (self::METRIC_DEFINITIONS as $metric => $definition) {
            $metrics[$metric] = [
                'metric' => $metric,
                'label' => $definition['label'],
                'state' => 'unknown',
                'value' => null,
                'bit' => (int) $definition['bit'],
            ];
        }

        return [
            'metrics' => $metrics,
            'meta' => [
                'error' => [
                    'code' => $errorCode,
                    'message' => $details,
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @return array<string, array<string, mixed>>
     */
    private function ensureAllMetrics(array $metrics): array
    {
        foreach (self::METRIC_DEFINITIONS as $metric => $definition) {
            if (array_key_exists($metric, $metrics)) {
                continue;
            }

            $metrics[$metric] = [
                'metric' => $metric,
                'label' => $definition['label'],
                'state' => 'unknown',
                'value' => null,
                'bit' => (int) $definition['bit'],
            ];
        }

        return $metrics;
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     */
    private function persistMetrics(array $metrics, bool $triggerNotifications): void
    {
        $notifications = [];

        DB::transaction(function () use ($metrics, $triggerNotifications, &$notifications): void {
            foreach (self::METRIC_DEFINITIONS as $metric => $definition) {
                $data = $metrics[$metric] ?? [
                    'state' => 'unknown',
                    'value' => null,
                    'bit' => $definition['bit'],
                ];

                /** @var DeviceHealthMetric $record */
                $record = DeviceHealthMetric::query()
                    ->lockForUpdate()
                    ->find($metric);

                if ($record === null) {
                    $record = new DeviceHealthMetric(['metric' => $metric]);
                }

                $previousState = $record->state ?? 'unknown';
                $state = (string) ($data['state'] ?? 'unknown');

                $record->state = $state;
                $record->meta = [
                    'value' => $data['value'] ?? null,
                    'bit' => $data['bit'] ?? null,
                ];

                if ($state === 'fault') {
                    $shouldNotify = $triggerNotifications
                        && ($record->last_fault_notified_at === null || $previousState !== 'fault');
                    if ($shouldNotify) {
                        $notifications[] = [
                            'metric' => $metric,
                            'definition' => $definition,
                            'data' => $data,
                        ];
                        $record->last_fault_notified_at = CarbonImmutable::now();
                    }
                } elseif ($state === 'ok') {
                    $record->last_fault_notified_at = null;
                }

                $record->save();
            }
        });

        foreach ($notifications as $notification) {
            $this->dispatchKppsFault(
                (string) $notification['metric'],
                $notification['definition'],
                (array) $notification['data']
            );
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     */
    private function dispatchKppsFault(string $metric, array $definition, array $data): void
    {
        $deviceLabel = $definition['label'] ?? $metric;
        $code = $definition['fault_code'] ?? null;
        if ($code === null) {
            return;
        }

        $detail = $definition['fault_detail'] ?? $metric;

        try {
            $result = $this->python->triggerJsvvFrame('FAULT', [
                'VP',
                (string) $code,
                $detail,
            ], true);

            Log::info('KPPS fault frame dispatched', [
                'metric' => $metric,
                'code' => $code,
                'detail' => $detail,
                'result' => Arr::get($result, 'json', $result),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Unable to dispatch KPPS fault frame', [
                'metric' => $metric,
                'device' => $deviceLabel,
                'code' => $code,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
