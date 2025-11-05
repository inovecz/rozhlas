<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class ModbusControlService
{
    private string $pythonBinary;
    private string $scriptPath;
    private string $port;
    private int $unitId;
    private ?float $timeout;

    public function __construct(
        ?string $pythonBinary = null,
        ?string $scriptPath = null,
        ?string $port = null,
        ?int $unitId = null,
        ?float $timeout = null,
    ) {
        $config = config('modbus', []);

        $this->pythonBinary = $pythonBinary ?? (string) Arr::get($config, 'python', 'python3');
        $this->scriptPath = $this->normaliseScriptPath($scriptPath ?? (string) Arr::get(
            $config,
            'script',
            base_path('python-client/modbus_control.py')
        ));
        $this->port = $port ?? (string) Arr::get($config, 'port', '/dev/ttyUSB0');
        $this->unitId = $unitId ?? (int) Arr::get($config, 'unit_id', 55);
        $timeoutValue = $timeout ?? Arr::get($config, 'timeout');
        $this->timeout = is_numeric($timeoutValue) ? (float) $timeoutValue : null;
    }

    /**
     * @param array<int, int|string>|null $zones
     * @param array<int, int|string>|null $route
     */
    public function startStream(?array $zones = null, ?array $route = null): array
    {
        $commandArguments = [];
        if ($route !== null && $route !== []) {
            $commandArguments[] = '--route';
            foreach ($route as $address) {
                if ($address === null || $address === '') {
                    continue;
                }
                $commandArguments[] = (string) $address;
            }
        }

        if ($zones !== null && $zones !== []) {
            $commandArguments[] = '--zones';
            foreach ($zones as $zone) {
                if ($zone === null || $zone === '') {
                    continue;
                }
                $commandArguments[] = (string) $zone;
            }
        }

        return $this->run('start-stream', $commandArguments);
    }

    public function stopStream(): array
    {
        return $this->run('stop-stream');
    }

    /**
     * @param array<int, string> $arguments
     */
    private function run(string $command, array $arguments = []): array
    {
        $fullCommand = [
            $this->pythonBinary,
            $this->scriptPath,
            '--port',
            $this->port,
            '--unit-id',
            (string) $this->unitId,
            $command,
            ...$arguments,
        ];

        Log::info('Running Modbus control command', [
            'command' => $fullCommand,
        ]);

        $process = new Process($fullCommand, base_path(), null, null, $this->timeout);
        $process->run();

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            throw new RuntimeException($errorOutput !== '' ? $errorOutput : 'Modbus command failed.');
        }

        return [
            'exit_code' => $process->getExitCode(),
            'output' => $output,
            'error_output' => $errorOutput,
        ];
    }

    private function normaliseScriptPath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return base_path('python-client/modbus_control.py');
        }

        if ($trimmed[0] === '~') {
            $home = rtrim((string) getenv('HOME'), '/');
            if ($home !== '') {
                return $home . '/' . ltrim(substr($trimmed, 1), '/');
            }
        }

        if ($this->isAbsolute($trimmed)) {
            return $trimmed;
        }

        return base_path(trim($trimmed, '/'));
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:\\\\#', $path);
    }
}
