<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Audio\AlsamixerService;
use App\Services\Mixer\MixerController;
use App\Settings\VolumeSettings;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class VolumeManager
{
    public function __construct(
        private readonly VolumeSettings $settings,
        private readonly MixerController $mixer,
        private readonly AlsamixerService $alsamixer,
    ) {
    }

    /**
     * Update a single level for the running session without persisting.
     *
     * @return array<string, mixed>
     */
    public function applyRuntimeLevel(string $groupId, string $itemId, float $value): array
    {
        $definition = $this->definitionsFor($groupId, $itemId);
        $value = $this->normalizeLevel($value);
        $this->applyToMixer($groupId, $itemId, $definition, $value);

        return [
            'id' => $itemId,
            'label' => $definition['label'] ?? $itemId,
            'value' => (float) $value,
            'default' => $this->normalizeLevel((float) ($definition['default'] ?? 0.0)),
        ];
    }

    /**
     * Retrieve all configured groups with their current and default values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listGroups(): array
    {
        $definitions = $this->definitions();

        $groups = [];
        foreach ($definitions as $groupId => $definition) {
            if (!is_array($definition) || !isset($definition['items'])) {
                continue;
            }

            $groups[] = [
                'id' => $groupId,
                'label' => $definition['label'] ?? $groupId,
                'items' => $this->formatItems($groupId, $definition['items']),
            ];
        }

        return $groups;
    }

    /**
     * Update a single level.
     *
     * @return array<string, mixed>
     */
    public function updateLevel(string $groupId, string $itemId, float $value): array
    {
        $definition = $this->definitionsFor($groupId, $itemId);

        $value = $this->normalizeLevel($value);
        $values = $this->getGroupValues($groupId);
        $values[$itemId] = $value;
        $this->setGroupValues($groupId, $values);
        $this->settings->save();

        $this->applyToMixer($groupId, $itemId, $definition, $value);

        return $this->formatItem($groupId, $itemId, $definition);
    }

    public function getCurrentLevel(string $groupId, string $itemId): float
    {
        $definition = $this->definitionsFor($groupId, $itemId);
        $values = $this->getGroupValues($groupId);

        $raw = (float) ($values[$itemId] ?? $definition['default'] ?? 0.0);

        return $this->normalizeLevel($raw);
    }

    /**
     * Update multiple levels at once and return the refreshed structure.
     *
     * @param array<int, array{id:string, items:array<int, array{id:string, value:float|int|string}>}> $groups
     * @return array<int, array<string, mixed>>
     */
    public function updateGroups(array $groups): array
    {
        $definitions = $this->definitions();
        $updates = [];

        foreach ($groups as $group) {
            $groupId = Arr::get($group, 'id');
            if (!is_string($groupId) || !isset($definitions[$groupId])) {
                throw new InvalidArgumentException(sprintf('Unknown volume group "%s".', (string) $groupId));
            }

            $items = Arr::get($group, 'items', []);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $itemId = Arr::get($item, 'id');
                if (!is_string($itemId)) {
                    continue;
                }

                $definition = $this->definitionsFor($groupId, $itemId);
                $value = $this->normalizeLevel((float) Arr::get($item, 'value', $definition['default'] ?? 0.0));
                $updates[] = [$groupId, $itemId, $definition, $value];

                $values = $this->getGroupValues($groupId);
                $values[$itemId] = $value;
                $this->setGroupValues($groupId, $values);
            }
        }

        $this->settings->save();

        foreach ($updates as [$groupId, $itemId, $definition, $value]) {
            $this->applyToMixer($groupId, $itemId, $definition, $value);
        }

        return $this->listGroups();
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        return config('volume', []);
    }

    /**
     * @param array<string, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function formatItems(string $groupId, array $items): array
    {
        $formatted = [];
        foreach ($items as $itemId => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $formatted[] = $this->formatItem($groupId, (string) $itemId, $definition);
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function formatItem(string $groupId, string $itemId, array $definition): array
    {
        $values = $this->getGroupValues($groupId);
        $default = $this->normalizeLevel((float) ($definition['default'] ?? 0.0));
        $current = $this->normalizeLevel((float) ($values[$itemId] ?? $default));

        return [
            'id' => $itemId,
            'label' => $definition['label'] ?? $itemId,
            'value' => $current,
            'default' => $default,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionsFor(string $groupId, string $itemId): array
    {
        $definitions = $this->definitions();
        $group = $definitions[$groupId] ?? null;
        if (!is_array($group) || !isset($group['items'][$itemId])) {
            throw new InvalidArgumentException(sprintf('Unknown volume item "%s.%s".', $groupId, $itemId));
        }

        return $group['items'][$itemId];
    }

    /**
     * @return array<string, float>
     */
    private function getGroupValues(string $group): array
    {
        return match ($group) {
            'inputs' => $this->settings->inputs ?? [],
            'outputs' => $this->settings->outputs ?? [],
            'playback' => $this->settings->playback ?? [],
            default => [],
        };
    }

    /**
     * @param array<string, float> $values
     */
    private function setGroupValues(string $group, array $values): void
    {
        switch ($group) {
            case 'inputs':
                $this->settings->inputs = $values;
                break;
            case 'outputs':
                $this->settings->outputs = $values;
                break;
            case 'playback':
                $this->settings->playback = $values;
                break;
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function applyToMixer(string $groupId, string $itemId, array $definition, float $value): void
    {
        if ($groupId === 'inputs') {
            $channel = isset($definition['channel']) ? (string) $definition['channel'] : '';
            if ($channel !== '' && $this->alsamixer->applyInputVolume($channel, $value)) {
                return;
            }
        }

        $template = $definition['command'] ?? config('volume.command_template');
        if ($template === null) {
            return;
        }

        $channel = $definition['channel'] ?? null;
        $label = $definition['label'] ?? $channel ?? 'channel';
        $control = $definition['alsa_control'] ?? $definition['channel'] ?? $label;
        if ($control === null) {
            return;
        }

        $normalized = $this->normalizeLevel($value);
        $percentInt = (int) round($normalized);
        $valueFormatted = number_format($normalized, 1, '.', '');

        $context = [
            'channel' => $channel ?? $label,
            'label' => $label,
            'alsa_control' => $control,
            'control' => $control,
            'value' => $valueFormatted,
            'value_percent' => $percentInt,
            'value_percent_str' => sprintf('%d%%', $percentInt),
        ];

        $this->mixer->runLevelCommand($template, $context, $label);
    }

    private function normalizeLevel(float $value): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }

        return max(0.0, min(100.0, $value));
    }

}
