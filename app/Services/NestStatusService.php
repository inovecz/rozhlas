<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LocationStatusEnum;
use App\Enums\LocationTypeEnum;
use App\Libraries\PythonClient;
use App\Models\Location;
use App\Settings\TwoWayCommSettings;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NestStatusService extends Service
{
    private const CACHE_KEY_LAST_RUN = 'two_way:nest_status:last_run';
    private const CACHE_TTL_MINUTES = 2880; // 2 days

    public function __construct(private readonly PythonClient $pythonClient = new PythonClient())
    {
        parent::__construct();
    }

    public function poll(bool $force = false): array
    {
        /** @var TwoWayCommSettings $settings */
        $settings = app(TwoWayCommSettings::class);
        if (!$force && !$settings->nestStatusAutoUpdate) {
            return [
                'updated' => 0,
                'skipped' => 'auto_update_disabled',
            ];
        }

        $locations = Location::query()
            ->where('type', LocationTypeEnum::NEST->value)
            ->whereNotNull('bidirectional_address')
            ->get();

        $routePrefix = $this->routePrefix();

        $updated = 0;
        $failures = [];

        foreach ($locations as $location) {
            $address = (int) ($location->bidirectional_address ?? 0);
            if ($address <= 0) {
                continue;
            }

            try {
                $response = $this->pythonClient->readNestStatus($address, $routePrefix);
                $data = $response['json']['data'] ?? $response['json'] ?? [];
                if (!is_array($data)) {
                    throw new \RuntimeException('Invalid response payload from python-client');
                }

                $statusValue = Arr::get($data, 'status');
                $errorValue = Arr::get($data, 'error');
                $status = $this->mapStatus($statusValue, $errorValue);

                if ($location->status !== $status) {
                    $location->status = $status;
                    $location->save();
                    $updated++;
                }
            } catch (Throwable $exception) {
                $failures[] = $location->id;
                Log::warning('Failed to read nest status', [
                    'location_id' => $location->id,
                    'address' => $address,
                    'message' => $exception->getMessage(),
                ]);

                if ($location->status !== LocationStatusEnum::UNKNOWN) {
                    $location->status = LocationStatusEnum::UNKNOWN;
                    $location->save();
                }
            }
        }

        $this->markRun(now());

        return [
            'updated' => $updated,
            'failures' => $failures,
        ];
    }

    public function determineNextRun(?Carbon $now = null): Carbon
    {
        $now = $now ?? now();
        /** @var TwoWayCommSettings $settings */
        $settings = app(TwoWayCommSettings::class);

        if (!$settings->nestStatusAutoUpdate) {
            return $now->copy()->addMinutes(5);
        }

        $firstRunToday = $this->firstRunToday($settings, $now);
        $lastRun = $this->getLastRun();
        $startOfToday = $now->copy()->startOfDay();

        if ($lastRun === null || $lastRun->lt($startOfToday)) {
            return $now->lessThan($firstRunToday) ? $firstRunToday : $now;
        }

        $interval = max((int) ($settings->nestNextReadInterval ?? 360), 1);
        $nextRun = $lastRun->copy()->addMinutes($interval);

        if ($nextRun->lt($firstRunToday)) {
            $nextRun = $firstRunToday;
        }

        return $nextRun;
    }

    public function shouldRunNow(bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        /** @var TwoWayCommSettings $settings */
        $settings = app(TwoWayCommSettings::class);
        if (!$settings->nestStatusAutoUpdate) {
            return false;
        }

        $nextRun = $this->determineNextRun();
        return now()->greaterThanOrEqualTo($nextRun);
    }

    public function markRun(Carbon $timestamp): void
    {
        Cache::put(self::CACHE_KEY_LAST_RUN, $timestamp->toIso8601String(), now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    public function getLastRun(): ?Carbon
    {
        $value = Cache::get(self::CACHE_KEY_LAST_RUN);
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function firstRunToday(TwoWayCommSettings $settings, Carbon $reference): Carbon
    {
        $time = $settings->nestFirstReadTime ?: '00:00';
        try {
            $first = Carbon::createFromFormat('H:i', $time, $reference->getTimezone() ?? config('app.timezone'));
        } catch (Throwable) {
            $first = null;
        }

        if ($first === false || $first === null) {
            $first = $reference->copy()->startOfDay();
        } else {
            $first = $first->setDate($reference->year, $reference->month, $reference->day);
        }

        return $first;
    }

    private function routePrefix(): array
    {
        $prefix = config('two_way.route_prefix', []);
        if (!is_array($prefix)) {
            return [];
        }

        $normalized = [];
        foreach ($prefix as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_numeric($value)) {
                $intVal = (int) $value;
                if (!in_array($intVal, $normalized, true)) {
                    $normalized[] = $intVal;
                }
            }
        }

        return $normalized;
    }

    private function mapStatus(mixed $statusValue, mixed $errorValue): LocationStatusEnum
    {
        if ($errorValue !== null && (int) $errorValue !== 0) {
            return LocationStatusEnum::ERROR;
        }

        if ($statusValue === null) {
            return LocationStatusEnum::UNKNOWN;
        }

        $status = (int) $statusValue;
        if ($status <= 0) {
            return LocationStatusEnum::OK;
        }

        if ($status === 1) {
            return LocationStatusEnum::WARNING;
        }

        return LocationStatusEnum::ERROR;
    }
}
