<?php

declare(strict_types=1);

namespace App\Console\Commands\Audio;

use App\Services\Audio\AudioIoService;
use App\Services\VolumeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class ApplyPresetCommand extends Command
{
    protected $signature = 'audio:preset
        {preset : Identifikátor presetu (např. microphone, system_audio, pc_webrtc, fm_radio, central_file)}
        {--skip-volume : Nepřenastavovat hlasitost podle uložených hodnot}
    ';

    protected $description = 'Aplikuje nakonfigurovaný audio preset (vstup, výstup a odpovídající hlasitosti).';

    public function handle(AudioIoService $audioService, VolumeManager $volumeManager): int
    {
        $presetId = strtolower(trim((string) $this->argument('preset')));
        if ($presetId === '') {
            $this->error('Musíte zadat identifikátor presetu.');
            return self::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $presets */
        $presets = config('audio.presets', []);
        $resolved = $this->resolvePreset($presetId, $presets);
        if ($resolved === null) {
            $this->error(sprintf('Neznámý audio preset "%s".', $presetId));
            return self::FAILURE;
        }

        $definition = $resolved['definition'];
        $baseId = $resolved['base_id'] ?? $resolved['id'] ?? $presetId;
        $displayName = $this->resolveLabel($definition, $presetId);

        if (!$this->applyInputSelection($audioService, $definition)) {
            return self::FAILURE;
        }

        if (!$this->applyOutputSelection($audioService, $definition)) {
            return self::FAILURE;
        }

        if (!$this->option('skip-volume')) {
            $this->applyVolumeForSources($volumeManager, [$presetId, $baseId]);
        } else {
            $this->line('Přeskakuji nastavení hlasitosti (--skip-volume).');
        }

        $this->info(sprintf('Preset "%s" (%s) byl úspěšně aplikován.', $displayName, $presetId));

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $presets
     * @param array<string, bool> $visited
     * @return array{id:string, base_id:string, definition:array<string, mixed>}|null
     */
    private function resolvePreset(string $presetId, array $presets, array $visited = []): ?array
    {
        if (isset($visited[$presetId])) {
            return null;
        }

        $definition = $presets[$presetId] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        if (isset($definition['alias_of'])) {
            $aliasId = (string) $definition['alias_of'];
            if ($aliasId === '' || $aliasId === $presetId) {
                return null;
            }

            $visited[$presetId] = true;
            $resolved = $this->resolvePreset($aliasId, $presets, $visited);
            if ($resolved === null) {
                return null;
            }

            $merged = array_merge(
                $resolved['definition'],
                Arr::except($definition, ['alias_of'])
            );

            return [
                'id' => $presetId,
                'base_id' => $resolved['base_id'] ?? $resolved['id'] ?? $aliasId,
                'definition' => $merged,
            ];
        }

        return [
            'id' => $presetId,
            'base_id' => $presetId,
            'definition' => $definition,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function applyInputSelection(AudioIoService $audioService, array $definition): bool
    {
        $inputId = isset($definition['input']) ? trim((string) $definition['input']) : '';
        if ($inputId === '') {
            $this->line('Preset neobsahuje definici vstupu, krok přeskočen.');
            return true;
        }

        try {
            $status = $audioService->setInput($inputId);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return false;
        } catch (\Throwable $exception) {
            $this->error(sprintf('Přepnutí vstupu selhalo: %s', $exception->getMessage()));
            return false;
        }

        $current = Arr::get($status, 'current.input');
        $label = is_array($current)
            ? ($current['label'] ?? $current['id'] ?? $inputId)
            : $inputId;

        $this->info(sprintf('Vstup byl přepnut na "%s".', $label));

        return true;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function applyOutputSelection(AudioIoService $audioService, array $definition): bool
    {
        $outputId = isset($definition['output']) ? trim((string) $definition['output']) : '';
        if ($outputId === '') {
            $this->line('Preset neobsahuje definici výstupu, krok přeskočen.');
            return true;
        }

        try {
            $status = $audioService->setOutput($outputId);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return false;
        } catch (\Throwable $exception) {
            $this->error(sprintf('Přepnutí výstupu selhalo: %s', $exception->getMessage()));
            return false;
        }

        $current = Arr::get($status, 'current.output');
        $label = is_array($current)
            ? ($current['label'] ?? $current['id'] ?? $outputId)
            : $outputId;

        $this->info(sprintf('Výstup byl přepnut na "%s".', $label));

        return true;
    }

    /**
     * @param array<int, string> $sourceIds
     */
    private function applyVolumeForSources(VolumeManager $volumeManager, array $sourceIds): void
    {
        $inputMap = config('volume.source_channels', []);
        $outputMap = config('volume.source_output_channels', []);
        $playbackMap = config('volume.source_playback_channels', []);

        $channelIds = [];
        foreach ($sourceIds as $source) {
            if (!is_string($source) || $source === '') {
                continue;
            }

            if (isset($inputMap[$source])) {
                $channelIds[] = (string) $inputMap[$source];
            }
            if (isset($outputMap[$source])) {
                $channelIds[] = (string) $outputMap[$source];
            }
            if (isset($playbackMap[$source])) {
                $channelIds[] = (string) $playbackMap[$source];
            }
        }

        $channelIds = array_values(array_unique(array_filter($channelIds, static fn ($value) => $value !== '')));
        if ($channelIds === []) {
            $this->line('Pro tento preset není definováno žádné nastavení hlasitosti.');
            return;
        }

        foreach ($channelIds as $channelId) {
            $this->applyVolumeChannel($volumeManager, $channelId);
        }
    }

    private function applyVolumeChannel(VolumeManager $volumeManager, string $channelId): void
    {
        $groupId = $this->detectVolumeGroup($channelId);
        if ($groupId === null) {
            $this->warn(sprintf('Nelze nastavit hlasitost pro "%s" – ovládací prvek nebyl nalezen.', $channelId));
            return;
        }

        try {
            $value = $volumeManager->getCurrentLevel($groupId, $channelId);
            $volumeManager->applyRuntimeLevel($groupId, $channelId, $value);
        } catch (InvalidArgumentException $exception) {
            $this->warn(sprintf(
                'Nastavení hlasitosti "%s.%s" selhalo: %s',
                $groupId,
                $channelId,
                $exception->getMessage()
            ));
            return;
        } catch (\Throwable $exception) {
            $this->warn(sprintf(
                'Nastavení hlasitosti "%s.%s" selhalo: %s',
                $groupId,
                $channelId,
                $exception->getMessage()
            ));
            return;
        }

        $label = config(sprintf('volume.%s.items.%s.label', $groupId, $channelId));
        $name = is_string($label) && $label !== '' ? $label : sprintf('%s.%s', $groupId, $channelId);

        $this->info(sprintf('Hlasitost "%s" nastavena na %.1f%%.', $name, $value));
    }

    private function detectVolumeGroup(string $channelId): ?string
    {
        foreach (['inputs', 'outputs', 'playback'] as $group) {
            $items = config(sprintf('volume.%s.items', $group), []);
            if (is_array($items) && array_key_exists($channelId, $items)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolveLabel(array $definition, string $fallback): string
    {
        $label = isset($definition['label']) ? trim((string) $definition['label']) : '';
        if ($label !== '') {
            return $label;
        }

        return $fallback;
    }
}
