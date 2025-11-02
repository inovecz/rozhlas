<?php

declare(strict_types=1);

namespace App\Services\RF\Driver;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DriverRs485Rts implements Rs485DriverInterface
{
    private string $device;
    private string $pythonBinary;
    private bool $txHigh;
    private bool $rxHigh;
    private float $leadSeconds;
    private float $tailSeconds;
    private bool $available = true;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->device = (string) ($config['device'] ?? '');
        $this->pythonBinary = (string) ($config['python'] ?? 'python3');
        $this->txHigh = $this->toBool($config['tx_high'] ?? true);
        $this->rxHigh = $this->toBool($config['rx_high'] ?? false);
        $this->leadSeconds = (float) ($config['lead'] ?? 0.0);
        $this->tailSeconds = (float) ($config['tail'] ?? 0.0);

        if ($this->device === '') {
            $this->available = false;
            Log::warning('RS-485 RTS driver disabled due to missing serial device.');
        }
    }

    public function enterTransmit(): void
    {
        if (!$this->available) {
            return;
        }

        $this->setRts($this->txHigh);

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

        $this->setRts($this->rxHigh);
    }

    public function shutdown(): void
    {
        if (!$this->available) {
            return;
        }

        $this->setRts($this->rxHigh);
    }

    private function setRts(bool $high): void
    {
        $script = <<<'PY'
import serial
import sys

port = sys.argv[1]
value = sys.argv[2] == '1'

ser = serial.Serial(port, timeout=0)
try:
    ser.rts = value
finally:
    ser.close()
PY;

        $process = new Process([
            $this->pythonBinary,
            '-c',
            $script,
            $this->device,
            $high ? '1' : '0',
        ]);
        $process->setTimeout(2);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::error('RS-485 RTS driver failed to toggle RTS.', [
                'device' => $this->device,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        if (!$process->isSuccessful()) {
            Log::error('RS-485 RTS driver command exited with error.', [
                'device' => $this->device,
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);
        } else {
            Log::debug('RS-485 RTS driver toggled RTS line.', [
                'device' => $this->device,
                'mode' => $high ? 'tx' : 'rx',
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
