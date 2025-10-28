<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JsvvAudioGroupEnum;
use App\Enums\JsvvAudioTypeEnum;
use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class JsvvAlarmDurationService
{
    /**
     * @var Collection<string, JsvvAudio>|null
     */
    private ?Collection $audioCache = null;

    /**
     * @var array{verbal: float, siren: float, fallback: float}
     */
    private array $defaults;

    public function __construct()
    {
        $config = config('jsvv.sequence.default_durations', []);
        $this->defaults = [
            'verbal' => (float) ($config['verbal'] ?? 12.0),
            'siren' => (float) ($config['siren'] ?? 60.0),
            'fallback' => (float) ($config['fallback'] ?? 10.0),
        ];
    }

    public function estimate(JsvvAlarm $alarm): ?float
    {
        $symbols = $this->resolveSequenceSymbols($alarm);
        if ($symbols === []) {
            return null;
        }

        $total = 0.0;
        $hadValue = false;

        foreach ($symbols as $symbol) {
            $duration = $this->estimateSymbolDuration($symbol);
            if ($duration === null) {
                return null;
            }
            $hadValue = true;
            $total += $duration;
        }

        if (!$hadValue || $total <= 0.0) {
            return null;
        }

        return $total;
    }

    /**
     * @return array<int, string>
     */
    private function resolveSequenceSymbols(JsvvAlarm $alarm): array
    {
        $sequence = $alarm->getSequence();
        if (!is_string($sequence) || $sequence === '') {
            return [];
        }

        $trimmed = trim($sequence);
        if ($trimmed === '') {
            return [];
        }

        $tokens = preg_split('//u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return [];
        }

        return array_map(
            static fn(string $symbol): string => mb_strtoupper($symbol),
            $tokens
        );
    }

    private function estimateSymbolDuration(string $symbol): ?float
    {
        /** @var JsvvAudio|null $audio */
        $audio = $this->audioMap()->get($symbol);

        if ($audio !== null && $audio->getType() === JsvvAudioTypeEnum::SOURCE) {
            // Live sources (e.g. microphone) do not have deterministic duration.
            return null;
        }

        $metadata = $audio?->file?->getMetadata();
        $duration = $this->extractDurationFromMetadata($metadata);
        if ($duration !== null) {
            return $duration;
        }

        $category = $this->resolveCategory($audio);
        return $this->defaultDuration($category);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function extractDurationFromMetadata(?array $metadata): ?float
    {
        if (!is_array($metadata)) {
            return null;
        }

        $keys = [
            'duration_seconds',
            'durationSeconds',
            'duration',
            'length',
        ];

        foreach ($keys as $key) {
            $value = Arr::get($metadata, $key);
            if (is_numeric($value)) {
                $numeric = (float) $value;
                if ($numeric > 0.0) {
                    return $numeric;
                }
            }
        }

        return null;
    }

    private function resolveCategory(?JsvvAudio $audio): string
    {
        $group = $audio?->getGroup();
        return match ($group?->value) {
            JsvvAudioGroupEnum::SIREN->value => 'siren',
            default => 'verbal',
        };
    }

    private function defaultDuration(string $category): float
    {
        return match ($category) {
            'siren' => $this->defaults['siren'],
            'verbal' => $this->defaults['verbal'],
            default => $this->defaults['fallback'],
        };
    }

    /**
     * @return Collection<string, JsvvAudio>
     */
    private function audioMap(): Collection
    {
        if ($this->audioCache === null) {
            $this->audioCache = JsvvAudio::query()
                ->with('file')
                ->get()
                ->keyBy(static fn(JsvvAudio $audio): string => mb_strtoupper($audio->getSymbol()));
        }

        return $this->audioCache;
    }
}
