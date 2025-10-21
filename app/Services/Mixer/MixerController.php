<?php

declare(strict_types=1);

namespace App\Services\Mixer;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class MixerController
{
    private bool $enabled;
    private ?string $binary;
    private int $timeout;
    private array $presets;
    private array $resetCommand;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('broadcast.mixer', []);

        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->binary = $config['binary'] ?? null;
        $this->timeout = (int) ($config['timeout'] ?? 10);
        $this->presets = $config['presets'] ?? [];
        $this->resetCommand = $config['reset'] ?? [];
    }

    public function activatePreset(string $source, array $context = []): void
    {
        $this->runPreset($source, $context, 'activate preset');
    }

    public function reset(array $context = []): void
    {
        if (!$this->enabled) {
            Log::debug('Mixer disabled: skipping reset command.');
            return;
        }

        if (empty($this->resetCommand)) {
            Log::debug('Mixer reset command not defined.');
            return;
        }

        $this->runCommand($this->substitute($this->resetCommand, $context), 'reset mixer');
    }

    private function runPreset(string $source, array $context, string $action): void
    {
        if (!$this->enabled) {
            Log::debug('Mixer disabled: skipping command for source.', ['source' => $source]);
            return;
        }

        $preset = $this->presets[$source] ?? $this->presets['default'] ?? null;
        if ($preset === null) {
            Log::warning('Mixer preset not found.', ['source' => $source]);
            return;
        }

        $preset = $this->substitute($preset, array_merge($context, ['source' => $source]));
        $this->runCommand($preset, $action . ' ' . $source);
    }

    /**
     * Execute a custom mixer command, typically used for per-channel volume adjustments.
     *
     * @param array<string, mixed>|string $definition
     * @param array<string, mixed> $context
     */
    public function runLevelCommand(array|string $definition, array $context = [], string $channelLabel = ''): void
    {
        if (!$this->enabled) {
            Log::debug('Mixer disabled: skipping level command.', [
                'channel' => $channelLabel ?: ($context['channel'] ?? null),
            ]);
            return;
        }

        $command = $this->substitute($definition, $context);
        $action = trim('set mixer level ' . $channelLabel);
        $this->runCommand($command, $action === '' ? 'set mixer level' : $action);
    }

    /**
     * @param array<string, mixed>|string $definition
     */
    private function runCommand(array|string $definition, string $action): void
    {
        try {
            $command = $this->buildCommand($definition);
        } catch (\RuntimeException $exception) {
            Log::error('Mixer command build failed.', [
                'action' => $action,
                'exception' => $exception->getMessage(),
            ]);
            return;
        }

        if (empty($command)) {
            Log::warning('Mixer command empty.', ['action' => $action]);
            return;
        }

        $process = is_string($command)
            ? Process::fromShellCommandline($command)
            : new Process($command);

        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::error('Mixer command failed to run.', [
                'action' => $action,
                'exception' => $exception->getMessage(),
            ]);
            return;
        }

        if (!$process->isSuccessful()) {
            Log::error('Mixer command finished with error.', [
                'action' => $action,
                'error' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
            ]);
            return;
        }

        Log::debug('Mixer command executed.', [
            'action' => $action,
            'output' => $process->getOutput(),
        ]);
    }

    /**
     * @param array<string, mixed>|string $definition
     * @return array<int, string>|string
     */
    private function buildCommand(array|string $definition): array|string
    {
        if (is_string($definition)) {
            return $definition;
        }

        if (isset($definition['command'])) {
            return (string) $definition['command'];
        }

        if ($this->binary === null) {
            throw new \RuntimeException('Mixer binary is not configured.');
        }

        $args = Arr::wrap($definition['args'] ?? []);
        return array_merge([$this->binary], array_map('strval', $args));
    }

    private function substitute(mixed $value, array $context): mixed
    {
        if (is_string($value)) {
            $replacements = [];
            foreach ($context as $key => $data) {
                if (is_scalar($data) || (is_object($data) && method_exists($data, '__toString'))) {
                    $replacements['{{' . $key . '}}'] = (string) $data;
                }
            }

            return strtr($value, $replacements);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->substitute($item, $context);
            }
            return $result;
        }

        return $value;
    }
}
