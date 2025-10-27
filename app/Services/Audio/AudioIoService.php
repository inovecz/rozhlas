<?php

declare(strict_types=1);

namespace App\Services\Audio;

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

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $config = $config ?? config('audio', []);

        $this->enabled = $this->normalizeBoolean($config['enabled'] ?? true);
        $this->card = (string) ($config['card'] ?? '0');

        $binaries = Arr::get($config, 'binaries', []);
        $this->amixer = (string) ($binaries['amixer'] ?? 'amixer');
        $this->aplay = (string) ($binaries['aplay'] ?? 'aplay');
        $this->arecord = (string) ($binaries['arecord'] ?? 'arecord');

        $this->timeout = (float) ($config['timeout'] ?? 5.0);
        $this->processEnv = array_filter(
            array_map('strval', Arr::get($config, 'process_env', [])),
            static fn ($value) => $value !== ''
        );
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

        $controls = Arr::get($definition, 'controls', []);
        if (!is_array($controls) || $controls === []) {
            throw new InvalidArgumentException(sprintf('Vstup "%s" nemá definované ovládací prvky.', $resolvedId));
        }

        foreach ($controls as $control => $value) {
            $controlName = (string) $control;
            if (!$this->controlExists($controlName)) {
                Log::warning('Audio input control missing, skipping.', [
                    'input' => $resolvedId,
                    'control' => $controlName,
                ]);
                continue;
            }
            $this->runAmixer(['sset', $controlName, (string) $value], sprintf('set input control %s', $controlName));
        }

        $muteControl = config('audio.inputs.mute_control');
        if (is_string($muteControl) && $muteControl !== '') {
            if ($this->controlExists($muteControl)) {
                $this->runAmixer(['sset', $muteControl, 'on'], sprintf('unmute capture (%s)', $muteControl), true);
            } else {
                Log::debug('Audio input mute control missing, skipping unmute.', [
                    'input' => $resolvedId,
                    'control' => $muteControl,
                ]);
            }
        }

        return $this->status();
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

        $controls = Arr::get($definition, 'controls', []);
        if (!is_array($controls) || $controls === []) {
            throw new InvalidArgumentException(sprintf('Výstup "%s" nemá definované ovládací prvky.', $identifier));
        }

        foreach ($controls as $control => $value) {
            $controlName = (string) $control;
            if (!$this->controlExists($controlName)) {
                Log::warning('Audio output control missing, skipping.', [
                    'output' => $identifier,
                    'control' => $controlName,
                ]);
                continue;
            }
            $this->runAmixer(['sset', $controlName, (string) $value], sprintf('set output control %s', $controlName));
        }

        $muteControl = config('audio.outputs.mute_control');
        if (is_string($muteControl) && $muteControl !== '') {
            if ($this->controlExists($muteControl)) {
                $this->runAmixer(['sset', $muteControl, 'on'], sprintf('unmute playback (%s)', $muteControl), true);
            } else {
                Log::debug('Audio output mute control missing, skipping unmute.', [
                    'output' => $identifier,
                    'control' => $muteControl,
                ]);
            }
        }

        return $this->status();
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
        $currentOutput = $this->enabled ? $this->detectSelection('outputs') : null;

        return [
            'enabled' => $this->enabled,
            'card' => $this->card,
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
                ];
            }
        }

        return [
            'raw' => $value,
            'id' => null,
            'label' => null,
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
