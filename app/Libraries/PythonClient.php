<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonClient
{
    private const DEFAULT_BINARY = 'python3';
    private const DEFAULT_SCRIPT = 'modbus_control.py';

    private string $pythonBinary;
    private string $scriptsRoot;
    private string $defaultScript;

    public function __construct(?string $pythonBinary = null, ?string $scriptsRoot = null, ?string $defaultScript = null)
    {
        $this->pythonBinary = $pythonBinary ?? (string) env('PYTHON_BINARY', self::DEFAULT_BINARY);
        $this->scriptsRoot = $scriptsRoot ?? base_path('python-client');
        $this->defaultScript = $defaultScript ?? self::DEFAULT_SCRIPT;
    }

    public function startStream(?array $route = null, ?array $zones = null): array
    {
        return $this->run('start-stream', [
            'route' => $route,
            'zones' => $zones,
        ]);
    }

    public function stopStream(): array
    {
        return $this->run('stop-stream');
    }

    public function getDeviceInfo(): array
    {
        return $this->run('device-info');
    }

    public function getStatusRegisters(): array
    {
        return $this->run('status');
    }

    public function probe(int|string|null $register = null): array
    {
        $options = [];
        if ($register !== null) {
            $options['register'] = $register;
        }

        return $this->run('probe', $options);
    }

    public function readRegister(int|string $address, int $count = 1): array
    {
        return $this->run('read-register', [
            'address' => $address,
            'count' => $count,
        ]);
    }

    public function writeRegister(int|string $address, int $value): array
    {
        return $this->run('write-register', [
            'address' => $address,
            'value' => $value,
        ]);
    }

    public function writeRegisters(int|string $address, array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values array must contain at least one element.');
        }

        return $this->run('write-registers', [
            'address' => $address,
            'values' => $values,
        ]);
    }

    public function readBlock(string $name): array
    {
        return $this->run('read-block', [
            'name' => $name,
        ]);
    }

    public function writeBlock(string $name, array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values array must contain at least one element.');
        }

        return $this->run('write-block', [
            'name' => $name,
            'values' => $values,
        ]);
    }

    public function run(string $command, array $options = [], ?float $timeout = null): array
    {
        return $this->callDefaultScript($this->buildCommandArguments($command, $options), $timeout);
    }

    public function callDefaultScript(array $arguments, ?float $timeout = null): array
    {
        return $this->call($this->defaultScript, $arguments, $timeout);
    }

    public function call(string $script, array $arguments = [], ?float $timeout = null): array
    {
        $scriptPath = $this->resolveScriptPath($script);
        $command = array_merge([$this->pythonBinary, $scriptPath], $arguments);

        $process = new Process($command, $this->scriptsRoot, null, null, $timeout);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        return [
            'success' => $process->isSuccessful(),
            'exitCode' => $process->getExitCode(),
            'stdout' => $this->normalizeOutput($stdout),
            'stderr' => $this->normalizeOutput($stderr),
            'json' => $this->decodeJson($stdout),
        ];
    }

    private function resolveScriptPath(string $script): string
    {
        if ($this->isAbsolutePath($script)) {
            $path = $script;
        } else {
            $path = $this->scriptsRoot . DIRECTORY_SEPARATOR . ltrim($script, DIRECTORY_SEPARATOR);
        }

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Python script "%s" not found in %s', $script, $this->scriptsRoot));
        }

        return $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:\\\\#', $path);
    }

    private function normalizeOutput(string $output): array
    {
        $normalized = preg_split("/\r\n|\n|\r/", rtrim($output, "\r\n"));
        if ($normalized === false) {
            return [];
        }

        return array_values(array_filter($normalized, static fn (string $line): bool => $line !== ''));
    }

    private function decodeJson(string $output): ?array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function buildCommandArguments(string $command, array $options): array
    {
        $arguments = [$command];

        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }

            $flag = '--' . str_replace('_', '-', (string) $key);

            if (is_bool($value)) {
                if ($value) {
                    $arguments[] = $flag;
                }
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }

                $arguments[] = $flag;
                foreach ($value as $item) {
                    $arguments[] = (string) $item;
                }
                continue;
            }

            $arguments[] = $flag;
            $arguments[] = (string) $value;
        }

        return $arguments;
    }
}
