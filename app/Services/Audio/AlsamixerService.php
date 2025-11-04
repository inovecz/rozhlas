<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class AlsamixerService
{
    private string $pythonBinary;
    private string $scriptPath;
    private ?string $card;
    private float $timeout;
    /** @var array<string, string> */
    private array $inputMap;
    /** @var array<string, array{group:string, channel:string}> */
    private array $inputVolumeMap;
    /** @var array<string, string> */
    private array $channelMap;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('audio.alsamixer', []);
        $this->pythonBinary = (string) Arr::get($config, 'python', 'python3');
        $this->scriptPath = $this->normaliseScriptPath((string) Arr::get($config, 'binary', base_path('python-client/alsamixer.py')));
        $card = Arr::get($config, 'card');
        $this->card = is_string($card) && $card !== '' ? $card : null;
        $this->timeout = (float) Arr::get($config, 'timeout', 8.0);
        $this->inputMap = $this->normaliseMapping(Arr::get($config, 'input_map', []));
        $this->inputVolumeMap = $this->normaliseVolumeMap(Arr::get($config, 'input_volume_channels', []));
        $this->channelMap = $this->normaliseMapping(Arr::get($config, 'volume_channels', []));
    }

    public function isEnabled(): bool
    {
        return is_file($this->scriptPath);
    }

    public function supportsInput(string $identifier): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return $this->resolveInputAlias($identifier) !== null;
    }

    public function selectInput(string $identifier, ?float $volume = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $alias = $this->resolveInputAlias($identifier);
        if ($alias === null) {
            throw new InvalidArgumentException(sprintf('Unknown ALSA mixer input "%s".', $identifier));
        }

        $arguments = [$alias];
        if ($volume !== null) {
            $arguments[] = (string) $this->clampPercent($volume);
        }

        try {
            $this->run($arguments, sprintf('select input "%s"', $alias));
            return true;
        } catch (RuntimeException $exception) {
            Log::warning('ALSA mixer helper failed to select input.', [
                'identifier' => $identifier,
                'alias' => $alias,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Apply runtime input volume (0-100). Falls back to direct input selection
     * with preserved routing when generic volume command fails.
     */
    public function applyInputVolume(?string $channel, float $value): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->setVolume($value)) {
            return true;
        }

        if (!is_string($channel) || trim($channel) === '') {
            return false;
        }

        $channelKey = strtolower(trim($channel));
        $mappedInput = $this->channelMap[$channelKey] ?? null;
        if ($mappedInput === null) {
            return false;
        }

        $percent = (string) $this->clampPercent($value);

        try {
            $this->run([$mappedInput, $percent], sprintf('reapply input "%s" for volume', $mappedInput));
            return true;
        } catch (RuntimeException $exception) {
            Log::warning('ALSA mixer helper failed to adjust input volume via fallback.', [
                'channel' => $channelKey,
                'input' => $mappedInput,
                'value' => $value,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    public function setVolume(float $value): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $percent = (string) $this->clampPercent($value);

        try {
            $this->run(['volume', $percent], sprintf('set runtime volume to %s%%', $percent));
            return true;
        } catch (RuntimeException $exception) {
            Log::warning('ALSA mixer helper failed to set runtime volume.', [
                'value' => $value,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @return array{group:string, channel:string}|null
     */
    public function volumeChannelForInput(string $identifier): ?array
    {
        $normalized = strtolower(trim($identifier));
        if ($normalized === '') {
            return null;
        }

        if (isset($this->inputVolumeMap[$normalized])) {
            return $this->inputVolumeMap[$normalized];
        }

        $alias = $this->resolveInputAlias($identifier, true);
        if ($alias !== null && isset($this->inputVolumeMap[$alias])) {
            return $this->inputVolumeMap[$alias];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $mapping
     * @return array<string, string>
     */
    private function normaliseMapping(mixed $mapping): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $result = [];
        foreach ($mapping as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $normalizedKey = strtolower(trim($key));
            $normalizedValue = strtolower(trim($value));
            if ($normalizedKey === '' || $normalizedValue === '') {
                continue;
            }

            $result[$normalizedKey] = $normalizedValue;
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $mapping
     * @return array<string, array{group:string, channel:string}>
     */
    private function normaliseVolumeMap(mixed $mapping): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $result = [];
        foreach ($mapping as $key => $definition) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower(trim($key));
            if ($normalizedKey === '') {
                continue;
            }

            if (is_array($definition)) {
                $group = isset($definition['group']) ? (string) $definition['group'] : '';
                $channel = isset($definition['channel']) ? (string) $definition['channel'] : '';
                if ($group !== '' && $channel !== '') {
                    $result[$normalizedKey] = [
                        'group' => $group,
                        'channel' => $channel,
                    ];
                }
                continue;
            }

            if (is_string($definition) && $definition !== '') {
                $result[$normalizedKey] = [
                    'group' => 'inputs',
                    'channel' => $definition,
                ];
            }
        }

        return $result;
    }

    private function normaliseScriptPath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return base_path('python-client/alsamixer.py');
        }

        if (str_starts_with($trimmed, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $trimmed)) {
            return $trimmed;
        }

        return base_path($trimmed);
    }

    private function resolveInputAlias(string $identifier, bool $allowFallback = false): ?string
    {
        $normalized = strtolower(trim($identifier));
        if ($normalized === '') {
            return null;
        }

        if (isset($this->inputMap[$normalized])) {
            return $this->inputMap[$normalized];
        }

        return $allowFallback ? $normalized : null;
    }

    /**
     * @param array<int, string> $arguments
     */
    private function run(array $arguments, string $context): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('ALSA mixer helper not available.');
        }

        $command = [$this->pythonBinary, $this->scriptPath];
        foreach ($arguments as $argument) {
            $command[] = (string) $argument;
        }
        if ($this->card !== null) {
            $command[] = '--card';
            $command[] = (string) $this->card;
        }

        $workingDirectory = dirname($this->scriptPath);
        if (!is_dir($workingDirectory)) {
            $workingDirectory = base_path();
        }

        $process = new Process($command, $workingDirectory);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        if ($process->isSuccessful()) {
            return;
        }

        $error = trim($process->getErrorOutput());
        $output = trim($process->getOutput());
        $message = $error !== '' ? $error : ($output !== '' ? $output : 'Unknown error');

        throw new RuntimeException(sprintf('%s (context: %s)', $message, $context));
    }

    private function clampPercent(float $value): int
    {
        if (!is_finite($value)) {
            return 0;
        }

        $normalized = max(0.0, min(100.0, $value));

        return (int) round($normalized);
    }
}
