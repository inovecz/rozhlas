<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ControlTabBridge extends Service
{
    private string $pythonBinary;
    private string $scriptPath;
    private float $timeout;

    public function __construct(?string $pythonBinary = null, ?string $scriptPath = null, ?float $timeout = null)
    {
        parent::__construct();

        $this->pythonBinary = $pythonBinary ?? (string) config('control_tab.cli.python', 'python3');
        $this->scriptPath = $this->normaliseScriptPath($scriptPath ?? (string) config('control_tab.cli.script', base_path('python-client/ct_listener.py')));
        $this->timeout = $timeout ?? (float) config('control_tab.cli.timeout', 5.0);
    }

    /**
     * @param array<int|string, string|null> $fields
     * @param array<string, mixed> $options
     * @return array{command: array<int, string>, exit_code: int, output: string, error_output: string, duration_ms: int}
     */
    public function sendFields(array $fields, array $options = []): array
    {
        if ($fields === []) {
            throw new InvalidArgumentException('At least one field must be provided for Control Tab update.');
        }

        $normalised = [];
        foreach ($fields as $key => $value) {
            $fieldId = (int) $key;
            if ($fieldId <= 0) {
                throw new InvalidArgumentException(sprintf('Invalid Control Tab field id: %s', $key));
            }
            $normalised[$fieldId] = $value === null ? '' : (string) $value;
        }

        try {
            $payload = json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode Control Tab fields payload.', 0, $exception);
        }

        $command = [
            $this->pythonBinary,
            $this->scriptPath,
            'send-fields',
            '--fields-json',
            $payload,
        ];

        $screen = Arr::get($options, 'screen');
        $panel = Arr::get($options, 'panel');
        if ($screen !== null) {
            $command[] = '--screen';
            $command[] = (string) (int) $screen;
        }
        if ($panel !== null) {
            $command[] = '--panel';
            $command[] = (string) (int) $panel;
        }

        $delayMs = Arr::get($options, 'delay_ms');
        if ($delayMs !== null) {
            $command[] = '--delay-ms';
            $command[] = (string) (float) $delayMs;
        }

        $switchPanel = (bool) Arr::get($options, 'switch_panel', false);
        if ($switchPanel) {
            $command[] = '--switch-panel';
            $panelStatus = Arr::get($options, 'panel_status');
            if ($panelStatus !== null) {
                $command[] = '--panel-status';
                $command[] = (string) (int) $panelStatus;
            }
            $panelRepeat = Arr::get($options, 'panel_repeat');
            if ($panelRepeat !== null) {
                $command[] = '--panel-repeat';
                $command[] = (string) max(1, (int) $panelRepeat);
            }
        }

        $dryRun = (bool) Arr::get($options, 'dry_run', false);
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $process = new Process($command, base_path());
        $process->setTimeout($this->timeout);

        $startedAt = microtime(true);
        $process->run();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $result = [
            'command' => $command,
            'exit_code' => $process->getExitCode() ?? 0,
            'output' => trim($process->getOutput()),
            'error_output' => trim($process->getErrorOutput()),
            'duration_ms' => $durationMs,
        ];

        if (!$process->isSuccessful()) {
            Log::error('Control Tab CLI failed', $result);
            throw new ProcessFailedException($process);
        }

        return $result;
    }

    private function normaliseScriptPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '${')) {
            return base_path('python-client/ct_listener.py');
        }

        if ($path[0] === '~') {
            $home = rtrim(getenv('HOME') ?: '', '/');
            if ($home !== '') {
                return $home . '/' . ltrim(substr($path, 1), '/');
            }
        }

        return $path;
    }
}
