<?php

declare(strict_types=1);

namespace App\Services\Mixer;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AudioDeviceService
{
    private string $binary;
    private bool $enabled;

    public function __construct(?string $binary = null)
    {
        $this->enabled = (bool) config('broadcast.mixer.enabled', false);
        $configured = $binary ?? config('broadcast.mixer.binary');
        $this->binary = $this->normalizeBinaryPath($configured ?? 'python-client/mixer_control.py');
    }

    /**
     * @return array<string, mixed>
     */
    public function listDevices(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $process = new Process([$this->binary, 'devices']);
        $process->setTimeout((float) config('broadcast.mixer.timeout', 10));

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::error('Audio device probe failed to run.', [
                'binary' => $this->binary,
                'exception' => $exception->getMessage(),
            ]);
            return [
                'error' => 'process_failed',
                'message' => $exception->getMessage(),
            ];
        }

        if (!$process->isSuccessful()) {
            Log::warning('Audio device probe exited with error.', [
                'binary' => $this->binary,
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);
            return [
                'error' => 'non_zero_exit',
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ];
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (\JsonException $exception) {
            Log::warning('Audio device probe returned invalid JSON.', [
                'binary' => $this->binary,
                'output' => $output,
                'exception' => $exception->getMessage(),
            ]);
            return [
                'error' => 'invalid_json',
                'raw' => $output,
            ];
        }
    }

    private function normalizeBinaryPath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return base_path('python-client/mixer_control.py');
        }

        if ($this->isAbsolutePath($trimmed)) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, './')) {
            $trimmed = substr($trimmed, 2);
        }

        return base_path($trimmed);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':';
    }
}
