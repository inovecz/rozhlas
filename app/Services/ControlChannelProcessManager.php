<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ControlChannelProcessManager
{
    private string $socketPath;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $pythonBinary,
        private readonly string $workerScript,
        private readonly string $projectRoot,
        private readonly string $logFile,
        private readonly int $startupTimeoutMs = 3000,
        private readonly array $environment = [],
        private readonly bool $autoStartEnabled = true,
    ) {
        $this->socketPath = $this->resolveSocketPath($endpoint);
    }

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    public function shouldHandleError(int $errno): bool
    {
        return in_array($errno, [2, 111], true); // ENOENT, ECONNREFUSED
    }

    public function ensureOnline(): void
    {
        if (!$this->autoStartEnabled) {
            return;
        }

        if ($this->socketExists()) {
            return;
        }

        $this->spawnWorker();
        $this->waitForSocket();
    }

    private function spawnWorker(): void
    {
        $command = sprintf(
            'nohup %s %s --endpoint %s >> %s 2>&1 &',
            escapeshellcmd($this->pythonBinary),
            escapeshellarg($this->workerScript),
            escapeshellarg($this->endpoint),
            escapeshellarg($this->logFile)
        );

        $socketDir = dirname($this->socketPath);
        if (!is_dir($socketDir) && !mkdir($socketDir, 0775, true) && !is_dir($socketDir)) {
            throw new RuntimeException(sprintf('Unable to create socket directory: %s', $socketDir));
        }

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            throw new RuntimeException(sprintf('Unable to create log directory: %s', $logDir));
        }

        $process = Process::fromShellCommandline(
            $command,
            $this->projectRoot,
            $this->buildEnvironment()
        );
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        Log::info('Control channel worker spawn requested.', [
            'command' => $command,
            'project_root' => $this->projectRoot,
        ]);
    }

    private function waitForSocket(): void
    {
        $deadline = microtime(true) + max(0.1, $this->startupTimeoutMs / 1000);
        while (microtime(true) < $deadline) {
            if ($this->socketExists()) {
                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException(sprintf(
            'Control channel socket %s did not appear within %dms after spawning the worker.',
            $this->socketPath,
            $this->startupTimeoutMs
        ));
    }

    private function socketExists(): bool
    {
        return file_exists($this->socketPath);
    }

    private function resolveSocketPath(string $endpoint): string
    {
        if (!str_starts_with($endpoint, 'unix://')) {
            throw new RuntimeException(sprintf('Unsupported control channel endpoint: %s', $endpoint));
        }

        $path = substr($endpoint, strlen('unix://'));
        if ($path === '') {
            throw new RuntimeException('Control channel endpoint does not contain a socket path.');
        }

        if ($path[0] !== DIRECTORY_SEPARATOR) {
            $path = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvironment(): array
    {
        $environment = [];

        $mergeSources = [$_ENV, $_SERVER];
        foreach ($mergeSources as $source) {
            foreach ($source as $key => $value) {
                if (!is_string($key) || isset($environment[$key]) || !is_scalar($value)) {
                    continue;
                }
                $environment[$key] = (string) $value;
            }
        }

        foreach ($this->environment as $key => $value) {
            if (!is_string($key) || $value === null || (is_string($value) && $value === '')) {
                continue;
            }

            if (is_bool($value)) {
                $environment[$key] = $value ? '1' : '0';
                continue;
            }

            if (is_scalar($value)) {
                $environment[$key] = (string) $value;
            }
        }

        return $environment;
    }
}
