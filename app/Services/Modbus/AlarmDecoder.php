<?php

declare(strict_types=1);

namespace App\Services\Modbus;

use Illuminate\Support\Arr;

class AlarmDecoder
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @var array<string, int>
     */
    private array $aliases;

    public function __construct(?array $config = null)
    {
        $config ??= config('modbus_alarms', []);
        $this->config = $config;
        $this->aliases = Arr::get($config, 'word_aliases', []);
    }

    /**
     * @param array<int, int> $data Raw values read from registers 0x3002-0x3009.
     * @return array<string, mixed>
     */
    public function decode(array $data): array
    {
        $definitions = Arr::get($this->config, 'definitions', []);
        foreach ($definitions as $definition) {
            if ($this->matchesDefinition($definition, $data)) {
                return $this->buildPayload($definition, $data);
            }
        }

        return $this->buildDefaultPayload($data);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, int> $data
     */
    private function matchesDefinition(array $definition, array $data): bool
    {
        $conditions = Arr::get($definition, 'conditions', []);
        if (!is_array($conditions) || $conditions === []) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, int> $data
     * @return array<string, mixed>
     */
    private function buildPayload(array $definition, array $data): array
    {
        $metrics = $this->extractMetrics($definition, $data);

        $placeholders = [];
        foreach (Arr::get($definition, 'message_tokens', []) as $key => $value) {
            if (is_string($value) && str_starts_with($value, '{') && str_ends_with($value, '}')) {
                $metricKey = trim($value, '{}');
                $placeholders[$key] = $metrics[$metricKey] ?? '';
                continue;
            }

            $placeholders[$key] = $value;
        }

        return [
            'matched' => true,
            'code' => Arr::get($definition, 'code'),
            'label' => Arr::get($definition, 'label'),
            'category' => Arr::get($definition, 'category', 'general'),
            'severity' => Arr::get($definition, 'severity', 'informational'),
            'metrics' => $metrics,
            'placeholders' => $placeholders,
            'raw' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildDefaultPayload(array $data): array
    {
        $defaults = Arr::get($this->config, 'defaults', []);
        $voltageScale = (float) Arr::get($defaults, 'voltage_scale', 0.01);
        $currentScale = (float) Arr::get($defaults, 'current_scale', 0.01);

        $voltageRaw = $data[$this->resolveWord('battery_voltage_raw')] ?? null;
        $currentRaw = $data[$this->resolveWord('battery_current_raw')] ?? null;

        $voltage = is_numeric($voltageRaw) ? $this->formatValue((float) $voltageRaw * $voltageScale, 2) : null;
        $current = is_numeric($currentRaw) ? $this->formatValue((float) $currentRaw * $currentScale, 2) : null;

        return [
            'matched' => false,
            'code' => 'unknown',
            'label' => 'Neznámý alarm',
            'category' => 'unknown',
            'severity' => 'warning',
            'metrics' => array_filter([
                'battery_voltage_v' => $voltage,
                'battery_current_a' => $current,
            ], static fn ($value) => $value !== null),
            'placeholders' => [],
            'raw' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, int> $data
     * @return array<string, string>
     */
    private function extractMetrics(array $definition, array $data): array
    {
        $result = [];
        foreach (Arr::get($definition, 'metrics', []) as $name => $config) {
            $wordIndex = $this->resolveWord(Arr::get($config, 'word'));
            $rawValue = $wordIndex !== null ? ($data[$wordIndex] ?? null) : null;
            if ($rawValue === null || !is_numeric($rawValue)) {
                continue;
            }

            $scale = (float) Arr::get($config, 'scale', 1.0);
            $precision = (int) Arr::get($config, 'precision', 0);
            $value = (float) $rawValue * $scale;
            $result[$name] = $this->formatValue($value, $precision);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<int, int> $data
     */
    private function evaluateCondition(array $condition, array $data): bool
    {
        $wordIndex = $this->resolveWord(Arr::get($condition, 'word'));
        if ($wordIndex === null) {
            return false;
        }

        $rawValue = $data[$wordIndex] ?? null;
        if (!is_numeric($rawValue)) {
            return false;
        }

        $value = (float) $rawValue;
        $operator = strtolower((string) Arr::get($condition, 'operator', 'eq'));
        $expected = (float) Arr::get($condition, 'value', 0);
        $mask = Arr::get($condition, 'mask');

        if (is_numeric($mask)) {
            $value = (int) $value & (int) $mask;
        }

        return match ($operator) {
            'eq' => $value == $expected,
            'neq' => $value != $expected,
            'lt' => $value < $expected,
            'lte' => $value <= $expected,
            'gt' => $value > $expected,
            'gte' => $value >= $expected,
            'bit_set' => ((int) $rawValue & (int) $expected) === (int) $expected,
            'bit_clear' => ((int) $rawValue & (int) $expected) === 0,
            default => false,
        };
    }

    /**
     * @param int|string|null $word
     */
    private function resolveWord(int|string|null $word): ?int
    {
        if ($word === null) {
            return null;
        }

        if (is_numeric($word)) {
            return (int) $word;
        }

        return $this->aliases[$word] ?? null;
    }

    private function formatValue(float $value, int $precision): string
    {
        if ($precision <= 0) {
            return (string) round($value);
        }

        return number_format($value, $precision, '.', '');
    }
}
