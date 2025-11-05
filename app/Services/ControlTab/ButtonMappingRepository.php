<?php

declare(strict_types=1);

namespace App\Services\ControlTab;

use App\Settings\JsvvSettings;
use Illuminate\Support\Arr;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

class ButtonMappingRepository
{
    public function __construct(
        private ?JsvvSettings $settings = null,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $settings = $this->resolveSettings();
        $fromSettings = [];

        if ($settings !== null) {
            try {
                $fromSettings = $this->normalise($settings->controlTabButtons ?? null);
            } catch (MissingSettings) {
                $fromSettings = [];
            }
        }
        if ($fromSettings !== []) {
            return $fromSettings;
        }

        return $this->normalise(config('control_tab.buttons', []));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $controlId): ?array
    {
        $mapping = $this->all();
        if (array_key_exists($controlId, $mapping)) {
            return $mapping[$controlId];
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function normalise(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (!is_int($key) && is_string($key) && ctype_digit($key)) {
                $key = (int) $key;
            }
            if (!is_int($key)) {
                continue;
            }

            $result[$key] = $config;
        }

        ksort($result, SORT_NUMERIC);

        return $result;
    }

    private function resolveSettings(): ?JsvvSettings
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        try {
            /** @var JsvvSettings $resolved */
            $resolved = app(JsvvSettings::class);
            $this->settings = $resolved;
        } catch (\Throwable) {
            $this->settings = null;
        }

        return $this->settings;
    }
}
