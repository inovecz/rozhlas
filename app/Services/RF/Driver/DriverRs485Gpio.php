<?php

declare(strict_types=1);

namespace App\Services\RF\Driver;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DriverRs485Gpio implements Rs485DriverInterface
{
    private string $binary;
    private string $chip;
    private int $line;
    private bool $activeHigh;
    private float $leadSeconds;
    private float $tailSeconds;
    private bool $available = true;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->binary = (string) ($config['binary'] ?? 'gpioset');
        $this->chip = (string) ($config['chip'] ?? '');
        $this->line = (int) ($config['line'] ?? -1);
        $this->activeHigh = $this->toBool(Arr::get($config, 'active_high', true));
        $this->leadSeconds = (float) ($config['lead'] ?? 0.0);
        $this->tailSeconds = (float) ($config['tail'] ?? 0.0);
        if ($this->binary === '' || $this->chip === '' || $this->line < 0) {
            $this->available = false;
            Log::warning('RS-485 GPIO driver disabled due to missing configuration.', [
                'binary' => $this->binary,
                'chip' => $this->chip,
                'line' => $this->line,
            ]);
        }
    }

    public function enterTransmit(): void
    {
        if (!$this->available) {
            return;
        }

        $this->writeState(true);

        if ($this->leadSeconds > 0) {
            usleep((int) round($this->leadSeconds * 1_000_000));
        }
    }

    public function enterReceive(): void
    {
        if (!$this->available) {
            return;
        }

        if ($this->tailSeconds > 0) {
            usleep((int) round($this->tailSeconds * 1_000_000));
        }

        $this->writeState(false);
    }

    public function shutdown(): void
    {
        if (!$this->available) {
            return;
        }

        $this->writeState(false);
    }

    private function writeState(bool $transmit): void
    {
        $value = $this->activeHigh ? ($transmit ? 1 : 0) : ($transmit ? 0 : 1);
        $command = [
            $this->binary,
            $this->chip,
        ];
        $command[] = sprintf('%d=%d', $this->line, $value);

        $process = new Process($command);
        $process->setTimeout(2);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::error('RS-485 GPIO driver failed to toggle line.', [
                'command' => $command,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        if (!$process->isSuccessful()) {
            Log::error('RS-485 GPIO driver command finished with error.', [
                'command' => $command,
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);
        } else {
            Log::debug('RS-485 GPIO driver toggled line.', [
                'command' => $command,
                'mode' => $transmit ? 'tx' : 'rx',
            ]);
        }
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
