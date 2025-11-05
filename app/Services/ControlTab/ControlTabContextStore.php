<?php

declare(strict_types=1);

namespace App\Services\ControlTab;

use Illuminate\Support\Facades\Cache;

class ControlTabContextStore
{
    private const CACHE_PREFIX = 'control_tab:context:';
    private const CACHE_TTL_SECONDS = 86400;

    public function get(?string $deviceId = null): array
    {
        $key = $this->cacheKey($deviceId);

        /** @var array<string, mixed>|null $context */
        $context = Cache::get($key);
        if (!is_array($context)) {
            $context = $this->fresh();
        }

        return $context;
    }

    public function put(array $context, ?string $deviceId = null): void
    {
        $key = $this->cacheKey($deviceId);
        Cache::put($key, $context, self::CACHE_TTL_SECONDS);
    }

    public function update(array $values, ?string $deviceId = null): array
    {
        $context = $this->get($deviceId);
        $context = array_replace_recursive($context, $values);
        $this->put($context, $deviceId);

        return $context;
    }

    public function resetLive(?string $deviceId = null): array
    {
        return $this->update([
            'current_live' => $this->fresh()['current_live'],
        ], $deviceId);
    }

    public function resetJsvv(?string $deviceId = null): array
    {
        return $this->update([
            'current_jsvv' => $this->fresh()['current_jsvv'],
        ], $deviceId);
    }

    private function cacheKey(?string $deviceId): string
    {
        $suffix = $deviceId !== null && $deviceId !== '' ? $deviceId : 'default';
        return self::CACHE_PREFIX . $suffix;
    }

    /**
     * @return array<string, mixed>
     */
    private function fresh(): array
    {
        return [
            'current_live' => [
                'active' => false,
                'started_at' => null,
                'elapsed_seconds' => 0,
                'localities' => [],
                'jingle' => null,
            ],
            'current_jsvv' => [
                'active' => false,
                'started_at' => null,
                'elapsed_seconds' => 0,
                'type' => null,
            ],
            'selections' => [
                'localities' => [],
                'jingle' => null,
            ],
        ];
    }
}
