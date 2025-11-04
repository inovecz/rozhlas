<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Models\Log as ActivityLog;
use App\Services\VolumeManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class AudioIoService
{
    private bool $enabled;
    private string $card;
    private string $amixer;
    private string $aplay;
    private string $arecord;
    private float $timeout;
    private string $fallbackOutputDevice;
    /**
     * @var array<string, string>
     */
    private array $processEnv;
    /**
     * @var array<string, array<int, string>>
     */
    private array $controlCache = [];

    /**
     * @var array<int, string>|null
     */
    private ?array $playbackDevices = null;

    /**
     * @var array<int, string>|null
     */
    private ?array $captureDevices = null;

    private ?AlsamixerService $alsamixer = null;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null, ?AlsamixerService $alsamixer = null)
    {
        $config = $config ?? config('audio', []);

        $this->enabled = $this->normalizeBoolean($config['enabled'] ?? true);
        $this->card = (string) ($config['card'] ?? '0');
        $this->fallbackOutputDevice = (string) ($config['fallback_output_device'] ?? 'default');

        $binaries = Arr::get($config, 'binaries', []);
        $this->amixer = (string) ($binaries['amixer'] ?? 'amixer');
        $this->aplay = (string) ($binaries['aplay'] ?? 'aplay');
        $this->arecord = (string) ($binaries['arecord'] ?? 'arecord');

        $this->timeout = (float) ($config['timeout'] ?? 5.0);
        $this->processEnv = array_filter(
            array_map('strval', Arr::get($config, 'process_env', [])),
            static fn ($value) => $value !== ''
        );

        if ($alsamixer !== null) {
            $this->alsamixer = $alsamixer;
        } else {
            try {
                $this->alsamixer = app(AlsamixerService::class);
            } catch (\Throwable) {
                $this->alsamixer = null;
            }
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listInputs(bool $withAvailability = true): array
    {
        $items = Arr::get(config('audio.inputs', []), 'items', []);
        if (!is_array($items)) {
            return [];
        }

        $definitions = [];
        foreach ($items as $identifier => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $resolvedId = $this->resolveAlias('inputs', (string) $identifier);
            $resolvedDefinition = $this->fetchDefinition('inputs', $resolvedId);
            if ($resolvedDefinition === null) {
                continue;
            }

            $definitions[] = [
                'id' => (string) $identifier,
                'alias_of' => $definition['alias_of'] ?? null,
                'label' => $definition['label'] ?? $resolvedDefinition['label'] ?? (string) $identifier,
                'device' => $definition['device'] ?? $resolvedDefinition['device'] ?? null,
                'available' => $withAvailability
                    ? $this->isInputAvailable($definition['device'] ?? $resolvedDefinition['device'] ?? null)
                    : null,
            ];
        }

        return $definitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOutputs(bool $withAvailability = true): array
    {
        $items = Arr::get(config('audio.outputs', []), 'items', []);
        if (!is_array($items)) {
            return [];
        }

        $definitions = [];
        foreach ($items as $identifier => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $definitions[] = [
                'id' => (string) $identifier,
                'label' => $definition['label'] ?? (string) $identifier,
                'device' => $definition['device'] ?? null,
                'available' => $withAvailability
                    ? $this->isOutputAvailable($definition['device'] ?? null)
                    : null,
            ];
        }

        return $definitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listVolumes(): array
    {
        $items = config('audio.volumes', []);
        if (!is_array($items)) {
            return [];
        }

        $definitions = [];
        foreach ($items as $identifier => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $definitions[] = [
                'id' => (string) $identifier,
                'label' => $definition['label'] ?? (string) $identifier,
                'type' => $definition['type'] ?? 'playback',
            ];
        }

        return $definitions;
    }

    /**
     * Set input routing using configured mixer controls.
     *
     * @return array<string, mixed> Updated status snapshot.
     */
    public function setInput(string $identifier): array
    {
        if (!$this->enabled) {
            Log::debug('Audio input change skipped because audio I/O is disabled.', [
                'input' => $identifier,
            ]);
            return $this->status();
        }

        $resolvedId = $this->resolveAlias('inputs', $identifier);
        $definition = $this->fetchDefinition('inputs', $resolvedId);
        if ($definition === null) {
            throw new InvalidArgumentException(sprintf('Neznámý vstup "%s".', $identifier));
        }

        $controlsDefinition = Arr::get($definition, 'controls', []);
        if (!is_array($controlsDefinition) || $controlsDefinition === []) {
            throw new InvalidArgumentException(sprintf('Vstup "%s" nemá definované ovládací prvky.', $resolvedId));
        }

        $controls = [];
        foreach ($controlsDefinition as $control => $value) {
            $controls[(string) $control] = (string) $value;
        }

        $label = (string) ($definition['label'] ?? $resolvedId);
        $context = [
            'identifier' => $identifier,
            'resolved_identifier' => $resolvedId,
            'label' => $label,
            'controls' => $controls,
        ];

        $alsamixerReady = $this->alsamixer !== null && $this->alsamixer->isEnabled();

        if ($alsamixerReady) {
            if (!$this->alsamixer->supportsInput($identifier)) {
                Log::debug('ALSA mixer helper skipping input (unsupported mapping).', [
                    'input' => $identifier,
                ]);
                return $this->status();
            }

            try {
                $volumeHint = $this->resolvePreferredVolumeForInput($identifier);
                $this->alsamixer->selectInput($identifier, $volumeHint);
                $status = $this->status();
                $context['driver'] = 'alsamixer';
                if ($volumeHint !== null) {
                    $context['volume_hint'] = $volumeHint;
                }
                $this->logAudioAction('input', 'success', $label, $context, $status);
                return $status;
            } catch (\Throwable $exception) {
                $context['driver'] = 'alsamixer';
                $context['error'] = $exception->getMessage();
                $this->logAudioAction('input', 'failed', $label, $context);
                throw new RuntimeException(sprintf('ALSA mixer helper failed: %s', $exception->getMessage()), 0, $exception);
            }
        }

        try {
            foreach ($controls as $controlName => $value) {
                if (!$this->controlExists($controlName)) {
                    Log::warning('Audio input control missing, skipping.', [
                        'input' => $resolvedId,
                        'control' => $controlName,
                    ]);
                    continue;
                }

                $this->runAmixer(['sset', $controlName, $value], sprintf('set input control %s', $controlName));
                $this->verifyControlState($controlName, $value, 'input', $resolvedId);
            }

            $muteControl = config('audio.inputs.mute_control');
            if (is_string($muteControl) && $muteControl !== '') {
                if ($this->controlExists($muteControl)) {
                    $muteResult = $this->runAmixer(['sset', $muteControl, 'on'], sprintf('unmute capture (%s)', $muteControl), true);
                    if ($muteResult !== null) {
                        $this->verifyControlState($muteControl, 'on', 'input_mute', $resolvedId, false);
                    }
                } else {
                    Log::debug('Audio input mute control missing, skipping unmute.', [
                        'input' => $resolvedId,
                        'control' => $muteControl,
                    ]);
                }
            }

            $status = $this->status();
            $context['verified'] = true;
            $this->logAudioAction('input', 'success', $label, $context, $status);

            return $status;
        } catch (\Throwable $exception) {
            $context['error'] = $exception->getMessage();
            $this->logAudioAction('input', 'failed', $label, $context);

            throw $exception;
        }
    }

    /**
     * Set output routing using configured mixer controls.
     *
     * @return array<string, mixed> Updated status snapshot.
     */
    public function setOutput(string $identifier): array
    {
        if (!$this->enabled) {
            Log::debug('Audio output change skipped because audio I/O is disabled.', [
                'output' => $identifier,
            ]);
            return $this->status();
        }

        $definition = $this->fetchDefinition('outputs', $identifier);
        if ($definition === null) {
            throw new InvalidArgumentException(sprintf('Neznámý výstup "%s".', $identifier));
        }

        $controlsDefinition = Arr::get($definition, 'controls', []);
        if (!is_array($controlsDefinition) || $controlsDefinition === []) {
            throw new InvalidArgumentException(sprintf('Výstup "%s" nemá definované ovládací prvky.', $identifier));
        }

        $controls = [];
        foreach ($controlsDefinition as $control => $value) {
            $controls[(string) $control] = (string) $value;
        }

        $label = (string) ($definition['label'] ?? $identifier);
        $context = [
            'identifier' => $identifier,
            'label' => $label,
            'controls' => $controls,
        ];

        try {
            foreach ($controls as $controlName => $value) {
                if (!$this->controlExists($controlName)) {
                    Log::warning('Audio output control missing, skipping.', [
                        'output' => $identifier,
                        'control' => $controlName,
                    ]);
                    continue;
                }

                $this->runAmixer(['sset', $controlName, $value], sprintf('set output control %s', $controlName));
                $this->verifyControlState($controlName, $value, 'output', $identifier);
            }

            $muteControl = config('audio.outputs.mute_control');
            if (is_string($muteControl) && $muteControl !== '') {
                if ($this->controlExists($muteControl)) {
                    $muteResult = $this->runAmixer(['sset', $muteControl, 'on'], sprintf('unmute playback (%s)', $muteControl), true);
                    if ($muteResult !== null) {
                        $this->verifyControlState($muteControl, 'on', 'output_mute', $identifier, false);
                    }
                } else {
                    Log::debug('Audio output mute control missing, skipping unmute.', [
                        'output' => $identifier,
                        'control' => $muteControl,
                    ]);
                }
            }

            $status = $this->status();
            $context['verified'] = true;
            $this->logAudioAction('output', 'success', $label, $context, $status);

            return $status;
        } catch (\Throwable $exception) {
            $context['error'] = $exception->getMessage();
            $this->logAudioAction('output', 'failed', $label, $context);

            throw $exception;
        }
    }

    /**
     * Adjust volume and optionally toggle mute state.
     *
     * @param float|int|string|null $value Percent value (0-100). When null only mute is applied.
     * @return array<string, mixed>
     */
    public function setVolume(string $scope, float|int|string|null $value, ?bool $mute = null): array
    {
        if (!$this->enabled) {
            Log::debug('Audio volume change skipped because audio I/O is disabled.', [
                'scope' => $scope,
                'value' => $value,
                'mute' => $mute,
            ]);
            return $this->volumeStatus($scope);
        }

        $definition = $this->volumeDefinition($scope);
        $handledByAlsamixer = false;

        $alsamixerReady = $this->alsamixer !== null && $this->alsamixer->isEnabled();

        if ($alsamixerReady
            && $this->alsamixer !== null
            && $value !== null
            && isset($definition['channel'])
            && is_string($definition['channel'])
        ) {
            try {
                $handledByAlsamixer = $this->alsamixer->applyInputVolume($definition['channel'], (float) $value);
            } catch (\Throwable $exception) {
                Log::warning('ALSA mixer helper failed to adjust runtime volume, falling back to amixer.', [
                    'scope' => $scope,
                    'channel' => $definition['channel'],
                    'error' => $exception->getMessage(),
                ]);
                $handledByAlsamixer = false;
            }

            if ($handledByAlsamixer) {
                $value = null;
            }
        }

        if ($value !== null) {
            $percent = $this->normalizePercent((float) $value);
            $this->runAmixer(
                ['sset', $definition['control'], sprintf('%d%%', (int) round($percent))],
                sprintf('set %s volume', $scope)
            );

            if ($percent > 0 && isset($definition['mute_control']) && is_string($definition['mute_control'])) {
                $this->runAmixer(['sset', $definition['mute_control'], 'on'], sprintf('unmute %s', $scope), true);
            }
        }

        if ($mute !== null && isset($definition['mute_control']) && is_string($definition['mute_control'])) {
            $this->runAmixer(
                ['sset', $definition['mute_control'], $mute ? 'off' : 'on'],
                sprintf('toggle mute for %s', $scope)
            );
        }

        return $this->volumeStatus($scope);
    }

    /**
     * Toggle mute state for a volume scope.
     *
     * @return array<string, mixed>
     */
    public function setMute(string $scope, bool $mute): array
    {
        if (!$this->enabled) {
            Log::debug('Audio mute change skipped because audio I/O is disabled.', [
                'scope' => $scope,
                'mute' => $mute,
            ]);
            return $this->volumeStatus($scope);
        }

        $definition = $this->volumeDefinition($scope);
        if (!isset($definition['mute_control']) || !is_string($definition['mute_control'])) {
            throw new InvalidArgumentException(sprintf('Hlasitost "%s" nepodporuje mute.', $scope));
        }

        $this->runAmixer(
            ['sset', $definition['mute_control'], $mute ? 'off' : 'on'],
            sprintf('set mute for %s', $scope)
        );

        return $this->volumeStatus($scope);
    }

    /**
     * Provide a full status snapshot suitable for REST responses.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $inputs = $this->enabled ? $this->listInputs() : $this->listInputs(false);
        $outputs = $this->enabled ? $this->listOutputs() : $this->listOutputs(false);
        $volumes = [];
        foreach (array_keys(config('audio.volumes', [])) as $scope) {
            $volumes[$scope] = $this->volumeStatus((string) $scope);
        }

        $currentInput = $this->enabled ? $this->detectSelection('inputs') : null;
        if ($this->enabled) {
            $fallbackInput = $this->detectSelectionByControls('inputs', $currentInput);
            if ($fallbackInput !== null) {
                $currentInput = $this->mergeSelectionData($currentInput, $fallbackInput);
            }
        }

        if ($this->enabled) {
            $currentOutput = $this->detectSelection('outputs');
            $fallbackOutput = $this->detectSelectionByControls('outputs', $currentOutput);
            if ($fallbackOutput !== null) {
                $currentOutput = $this->mergeSelectionData($currentOutput, $fallbackOutput);
            }
        } else {
            $currentOutput = [
                'id' => null,
                'label' => null,
                'raw' => null,
                'device' => $this->fallbackOutputDevice,
                'fallback' => true,
            ];
        }

        return [
            'enabled' => $this->enabled,
            'card' => $this->card,
            'fallback_output_device' => $this->fallbackOutputDevice,
            'current' => [
                'input' => $currentInput,
                'output' => $currentOutput,
            ],
            'inputs' => $inputs,
            'outputs' => $outputs,
            'volumes' => $volumes,
            'timestamp' => Date::now()->toIso8601String(),
        ];
    }

    private function resolvePreferredVolumeForInput(string $identifier): ?float
    {
        if ($this->alsamixer === null || !$this->alsamixer->isEnabled()) {
            return null;
        }

        $mapping = $this->alsamixer->volumeChannelForInput($identifier);
        if ($mapping === null) {
            return null;
        }

        $group = $mapping['group'] ?? null;
        $channel = $mapping['channel'] ?? null;
        if (!is_string($group) || $group === '' || !is_string($channel) || $channel === '') {
            return null;
        }

        try {
            /** @var VolumeManager $volumeManager */
            $volumeManager = app(VolumeManager::class);
            return $volumeManager->getCurrentLevel($group, $channel);
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve preferred volume for ALSA mixer helper.', [
                'identifier' => $identifier,
                'group' => $group,
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudioAction(string $action, string $result, string $label, array $context, ?array $status = null): void
    {
        $title = $action === 'input' ? 'Přepnutí audio vstupu' : 'Přepnutí audio výstupu';
        $base = $action === 'input' ? 'Audio vstup' : 'Audio výstup';
        $description = $result === 'success'
            ? sprintf('%s "%s" byl úspěšně nastaven.', $base, $label)
            : sprintf('%s "%s" se nepodařilo nastavit.', $base, $label);

        if ($result !== 'success' && isset($context['error']) && is_string($context['error'])) {
            $description .= ' ' . $context['error'];
        }

        $data = array_merge([
            'action' => $action,
            'result' => $result,
            'label' => $label,
        ], $context);

        if ($status !== null) {
            $data['snapshot'] = [
                'current' => Arr::get($status, 'current'),
                'timestamp' => Arr::get($status, 'timestamp'),
            ];
        }

        try {
            ActivityLog::create([
                'type' => 'audio',
                'title' => $title,
                'description' => $description,
                'data' => $data,
            ]);
        } catch (\Throwable $exception) {
            Log::debug('Unable to record audio activity log.', [
                'action' => $action,
                'result' => $result,
                'label' => $label,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function verifyControlState(string $controlName, string $expectedValue, string $targetType, string $targetId, bool $required = true): void
    {
        $currentValue = $this->readEnumControl($controlName);
        if ($currentValue === null) {
            Log::debug('Audio control verification unavailable.', [
                'control' => $controlName,
                'expected' => $expectedValue,
                'type' => $targetType,
                'identifier' => $targetId,
                'required' => $required,
            ]);

            if ($required) {
                throw new RuntimeException(sprintf(
                    'Nepodařilo se ověřit stav ovládacího prvku "%s".',
                    $controlName
                ));
            }

            return;
        }

        $normalizedExpected = strtolower(trim($expectedValue));
        $normalizedActual = strtolower(trim($currentValue));

        if ($normalizedExpected !== $normalizedActual) {
            Log::error('Audio control verification failed.', [
                'control' => $controlName,
                'expected' => $expectedValue,
                'actual' => $currentValue,
                'type' => $targetType,
                'identifier' => $targetId,
            ]);

            throw new RuntimeException(sprintf(
                'Ovládací prvek "%s" neodpovídá očekávanému stavu (očekáváno "%s", zjištěno "%s").',
                $controlName,
                $expectedValue,
                $currentValue
            ));
        }
    }

    private function detectSelectionByControls(string $group, ?array $hint = null): ?array
    {
        $items = config(sprintf('audio.%s.items', $group), []);
        if (!is_array($items)) {
            return null;
        }

        $normalizedItems = [];
        foreach ($items as $key => $definition) {
            if (is_array($definition)) {
                $normalizedItems[(string) $key] = $definition;
            }
        }

        if ($normalizedItems === []) {
            return null;
        }

        $order = [];
        if (is_array($hint)) {
            $hintId = $hint['id'] ?? null;
            if (is_string($hintId) && $hintId !== '' && isset($normalizedItems[$hintId])) {
                $order[] = $hintId;
            }
        }

        foreach (array_keys($normalizedItems) as $identifier) {
            if (!in_array($identifier, $order, true)) {
                $order[] = $identifier;
            }
        }

        foreach ($order as $identifier) {
            $resolved = $this->fetchDefinition($group, $identifier);
            if (!is_array($resolved)) {
                continue;
            }

            $controls = Arr::get($resolved, 'controls', []);
            if (!is_array($controls) || $controls === []) {
                continue;
            }

            $allMatch = true;
            foreach ($controls as $controlName => $expectedValue) {
                $controlValue = $this->readEnumControl((string) $controlName);
                if ($controlValue === null) {
                    $allMatch = false;
                    break;
                }

                $normalizedExpected = strtolower(trim((string) $expectedValue));
                $normalizedActual = strtolower(trim($controlValue));
                if ($normalizedExpected !== $normalizedActual) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                $baseDefinition = $normalizedItems[$identifier];

                return [
                    'id' => $identifier,
                    'label' => $resolved['label'] ?? $baseDefinition['label'] ?? $identifier,
                    'device' => $resolved['device'] ?? $baseDefinition['device'] ?? null,
                    'verified' => true,
                ];
            }
        }

        return null;
    }

    private function mergeSelectionData(?array $original, array $verified): array
    {
        if ($original === null) {
            return $verified;
        }

        foreach ($verified as $key => $value) {
            if ($value === null) {
                continue;
            }
            $original[$key] = $value;
        }

        return $original;
    }

    /**
     * @param array<string, mixed>|null $definition
     * @return array<string, mixed>
     */
    private function volumeDefinition(string $scope, ?array $definition = null): array
    {
        $definition ??= config(sprintf('audio.volumes.%s', $scope));
        if (!is_array($definition) || !isset($definition['control'])) {
            throw new InvalidArgumentException(sprintf('Neznámý ovládací prvek hlasitosti "%s".', $scope));
        }

        return $definition;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function detectSelection(string $group): ?array
    {
        $primaryControl = config(sprintf('audio.%s.primary_control', $group));
        if (!is_string($primaryControl) || $primaryControl === '') {
            return null;
        }

        $value = $this->readEnumControl($primaryControl);
        if ($value === null) {
            return null;
        }

        $items = config(sprintf('audio.%s.items', $group), []);
        if (!is_array($items)) {
            return [
                'raw' => $value,
                'id' => null,
                'label' => null,
            ];
        }

        foreach ($items as $identifier => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $resolvedDefinition = $this->fetchDefinition($group, (string) $identifier);
            if (!is_array($resolvedDefinition)) {
                continue;
            }
            $controls = Arr::get($resolvedDefinition, 'controls', []);
            if (!is_array($controls)) {
                continue;
            }
            $targetValue = $controls[$primaryControl] ?? null;
            if ($targetValue !== null && strcasecmp((string) $targetValue, $value) === 0) {
                return [
                    'id' => (string) $identifier,
                    'label' => $definition['label'] ?? $resolvedDefinition['label'] ?? (string) $identifier,
                    'raw' => $value,
                    'device' => $definition['device'] ?? $resolvedDefinition['device'] ?? null,
                ];
            }
        }

        return [
            'raw' => $value,
            'id' => null,
            'label' => null,
            'device' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function volumeStatus(string $scope): array
    {
        $definition = $this->volumeDefinition($scope);
        $control = (string) $definition['control'];
        $muteControl = isset($definition['mute_control']) && is_string($definition['mute_control'])
            ? $definition['mute_control']
            : null;

        $reading = $this->readVolumeControl($control);
        $muteState = $muteControl !== null ? $this->readSwitchState($muteControl) : null;

        return [
            'id' => $scope,
            'label' => $definition['label'] ?? $scope,
            'value' => $reading['percent'],
            'raw' => $reading['raw'],
            'mute' => $muteState,
        ];
    }

    private function resolveAlias(string $group, string $identifier): string
    {
        $items = config(sprintf('audio.%s.items', $group), []);
        if (!is_array($items)) {
            throw new InvalidArgumentException(sprintf('Skupina audio.%s není nakonfigurovaná.', $group));
        }

        $visited = [];
        $current = $identifier;

        while (true) {
            if (isset($visited[$current])) {
                throw new InvalidArgumentException(sprintf('Cyklická alias definice pro "%s".', $identifier));
            }
            $visited[$current] = true;

            $definition = $items[$current] ?? null;
            if (!is_array($definition)) {
                if ($current === $identifier) {
                    break;
                }

                throw new InvalidArgumentException(sprintf('Alias "%s" neexistuje.', $current));
            }

            $alias = $definition['alias_of'] ?? null;
            if (!is_string($alias) || $alias === '') {
                break;
            }

            $current = $alias;
        }

        return $current;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDefinition(string $group, string $identifier): ?array
    {
        $definition = config(sprintf('audio.%s.items.%s', $group, $identifier));
        if (!is_array($definition)) {
            return null;
        }

        $alias = $definition['alias_of'] ?? null;
        if (is_string($alias) && $alias !== '' && $alias !== $identifier) {
            $resolved = $this->fetchDefinition($group, $alias);
            if ($resolved !== null) {
                return array_merge($resolved, array_filter($definition, static fn ($value, $key) => $key !== 'alias_of', ARRAY_FILTER_USE_BOTH));
            }
        }

        return $definition;
    }

    private function normalizePercent(float $value): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }

        return max(0.0, min(100.0, $value));
    }

    /**
     * @param array<int, string> $args
     */
    private function runAmixer(array $args, string $action, bool $allowFailure = false): ?string
    {
        $command = array_merge([$this->amixer], $this->card !== '' ? ['-c', $this->card] : [], $args);
        return $this->runProcess($command, $action, $allowFailure);
    }

    private function runProcess(array $command, string $action, bool $allowFailure = false): ?string
    {
        if (!$this->enabled) {
            Log::debug('Audio command skipped because audio I/O is disabled.', [
                'action' => $action,
                'command' => $command,
                'allow_failure' => $allowFailure,
            ]);
            return $allowFailure ? null : '';
        }

        $process = new Process($command);
        $process->setTimeout($this->timeout);

        if ($this->processEnv !== []) {
            $process->setEnv(array_merge($process->getEnv(), $this->processEnv));
        }

        try {
            $process->run();
        } catch (\Throwable $exception) {
            if ($allowFailure) {
                Log::warning('Audio command failed to run.', [
                    'action' => $action,
                    'command' => $command,
                    'exception' => $exception->getMessage(),
                ]);
                return null;
            }

            throw new RuntimeException(sprintf('Příkaz "%s" selhal: %s', implode(' ', $command), $exception->getMessage()), 0, $exception);
        }

        if (!$process->isSuccessful()) {
            if ($allowFailure) {
                Log::warning('Audio command exited with error status.', [
                    'action' => $action,
                    'command' => $command,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                ]);
                return null;
            }

            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @return array{percent: float|null, raw: string|null}
     */
    private function readVolumeControl(string $control): array
    {
        $output = $this->runAmixer(['sget', $control], sprintf('read volume %s', $control), true);
        if ($output === null) {
            return ['percent' => null, 'raw' => null];
        }

        preg_match_all('/\[(\d{1,3})%\]/', $output, $matches);
        $percentages = array_map('intval', $matches[1] ?? []);

        $percent = null;
        if ($percentages !== []) {
            $percent = array_sum($percentages) / count($percentages);
        }

        return [
            'percent' => $percent,
            'raw' => trim($output),
        ];
    }

    private function readSwitchState(string $control): ?bool
    {
        $output = $this->runAmixer(['sget', $control], sprintf('read switch %s', $control), true);
        if ($output === null) {
            return null;
        }

        preg_match_all('/\[(on|off)\]/i', $output, $matches);
        $states = array_map(static fn ($value) => strtolower($value), $matches[1] ?? []);
        if ($states === []) {
            return null;
        }

        $hasOn = in_array('on', $states, true);
        $hasOff = in_array('off', $states, true);

        if ($hasOn && !$hasOff) {
            return false; // explicitly unmuted
        }

        if ($hasOff && !$hasOn) {
            return true; // explicitly muted
        }

        return null;
    }

    private function readEnumControl(string $control): ?string
    {
        $output = $this->runAmixer(['sget', $control], sprintf('read control %s', $control), true);
        if ($output === null) {
            return null;
        }

        if (preg_match("/Item0:\\s*'([^']+)'/i", $output, $match)) {
            return trim($match[1]);
        }

        if (preg_match('/\[(on|off)\]/i', $output, $match)) {
            return strtolower($match[1]) === 'on' ? 'on' : 'off';
        }

        return null;
    }

    private function isInputAvailable(?string $device): ?bool
    {
        if ($device === null || $device === '') {
            return null;
        }

        $devices = $this->listCaptureDevices();
        if ($devices === null) {
            return null;
        }

        foreach ($devices as $entry) {
            if (stripos($entry, $device) !== false) {
                return true;
            }
        }

        return null;
    }

    private function isOutputAvailable(?string $device): ?bool
    {
        if ($device === null || $device === '') {
            return null;
        }

        $devices = $this->listPlaybackDevices();
        if ($devices === null) {
            return null;
        }

        foreach ($devices as $entry) {
            if (stripos($entry, $device) !== false) {
                return true;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>|null
     */
    private function listPlaybackDevices(): ?array
    {
        if ($this->playbackDevices !== null) {
            return $this->playbackDevices;
        }

        $output = $this->runProcess([$this->aplay, '-L'], 'list playback devices', true);
        if ($output === null) {
            $this->playbackDevices = null;
            return null;
        }

        return $this->playbackDevices = $this->parseLogicalDeviceList($output);
    }

    /**
     * @return array<int, string>|null
     */
    private function listCaptureDevices(): ?array
    {
        if ($this->captureDevices !== null) {
            return $this->captureDevices;
        }

        $output = $this->runProcess([$this->arecord, '-L'], 'list capture devices', true);
        if ($output === null) {
            $this->captureDevices = null;
            return null;
        }

        return $this->captureDevices = $this->parseLogicalDeviceList($output);
    }

    /**
     * @return array<int, string>
     */
    private function parseLogicalDeviceList(string $output): array
    {
        $devices = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if ($line !== '' && $line[0] === ' ') {
                continue;
            }

            $name = $trimmed;
            $spacePos = strpos($trimmed, ' ');
            if ($spacePos !== false) {
                $name = substr($trimmed, 0, $spacePos);
            }

            $devices[] = $name;
        }

        return $devices;
    }

    private function controlExists(string $control): bool
    {
        $control = trim($control);
        if ($control === '') {
            return false;
        }

        $card = $this->card;
        if (!isset($this->controlCache[$card])) {
            $this->controlCache[$card] = $this->loadAvailableControls($card);
        }

        return in_array($control, $this->controlCache[$card], true);
    }

    /**
     * @return array<int, string>
     */
    private function loadAvailableControls(string $card): array
    {
        $controls = [];

        $output = $this->runProcess([$this->amixer, '-c', $card, 'controls'], 'list mixer controls', true);
        if ($output !== null) {
            foreach (preg_split('/\r?\n/', $output) as $line) {
                if (preg_match("/name='([^']+)'/", $line, $match)) {
                    $controls[] = $match[1];
                }
            }
        }

        $simple = $this->runProcess([$this->amixer, '-c', $card, 'scontrols'], 'list simple mixer controls', true);
        if ($simple !== null) {
            foreach (preg_split('/\r?\n/', $simple) as $line) {
                if (preg_match("/Simple mixer control '([^']+)'/", $line, $match)) {
                    if (!in_array($match[1], $controls, true)) {
                        $controls[] = $match[1];
                    }
                }
            }
        }

        return $controls;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['false', '0', 'off', 'no', 'none'], true)) {
                return false;
            }
            if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }
}
