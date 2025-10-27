<?php

declare(strict_types=1);

namespace App\Services\Mixer;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PulseLoopbackManager
{
    private string $stateFile;

    public function __construct(?string $stateFile = null)
    {
        $this->stateFile = $stateFile ?? storage_path('app/pulse-loopback.json');
    }

    public function ensure(string $sourceId, string $sinkId): void
    {
        $source = $this->normalizePulseId($sourceId);
        $sink = $this->normalizePulseId($sinkId);

        if ($source === null || $sink === null) {
            return;
        }

        $state = $this->readState();
        if ($state !== null
            && $state['source'] === $source
            && $state['sink'] === $sink
            && $this->moduleExists($state['moduleId'])
        ) {
            return;
        }

        if ($state !== null) {
            $this->unloadModule($state['moduleId']);
            $this->writeState(null);
        }

        $moduleId = $this->loadModule($source, $sink);
        if ($moduleId !== null) {
            $this->writeState([
                'moduleId' => $moduleId,
                'source' => $source,
                'sink' => $sink,
            ]);
        }
    }

    public function clear(): void
    {
        $state = $this->readState();
        if ($state === null) {
            return;
        }

        $this->unloadModule($state['moduleId']);
        $this->writeState(null);
    }

    private function normalizePulseId(string $identifier): ?string
    {
        if (!str_starts_with($identifier, 'pulse:')) {
            return null;
        }
        $name = substr($identifier, strlen('pulse:'));
        return $name !== '' ? $name : null;
    }

    private function loadModule(string $source, string $sink): ?int
    {
        $process = new Process([
            'pactl',
            'load-module',
            'module-loopback',
            "source={$source}",
            "sink={$sink}",
            'latency_msec=50',
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error('Failed to create PulseAudio loopback.', [
                'source' => $source,
                'sink' => $sink,
                'error' => $process->getErrorOutput(),
            ]);
            return null;
        }

        $output = trim($process->getOutput());
        if ($output === '' || !ctype_digit($output)) {
            Log::warning('Unexpected output from pactl load-module.', [
                'source' => $source,
                'sink' => $sink,
                'output' => $output,
            ]);
            return null;
        }

        return (int) $output;
    }

    private function unloadModule(int $moduleId): void
    {
        $process = new Process(['pactl', 'unload-module', (string) $moduleId]);
        $process->run();
        if (!$process->isSuccessful()) {
            Log::warning('Failed to unload PulseAudio loopback module.', [
                'module_id' => $moduleId,
                'error' => $process->getErrorOutput(),
            ]);
        }
    }

    private function moduleExists(int $moduleId): bool
    {
        $process = new Process(['pactl', 'list', 'modules', 'short']);
        $process->run();
        if (!$process->isSuccessful()) {
            return false;
        }

        $needle = (string) $moduleId;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (isset($columns[0]) && $columns[0] === $needle) {
                return true;
            }
        }

        return false;
    }

    private function readState(): ?array
    {
        if (!is_file($this->stateFile)) {
            return null;
        }

        $contents = file_get_contents($this->stateFile);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['moduleId'], $decoded['source'], $decoded['sink'])) {
            return null;
        }

        return [
            'moduleId' => (int) $decoded['moduleId'],
            'source' => (string) $decoded['source'],
            'sink' => (string) $decoded['sink'],
        ];
    }

    private function writeState(?array $state): void
    {
        if ($state === null) {
            if (is_file($this->stateFile)) {
                @unlink($this->stateFile);
            }
            return;
        }

        $directory = dirname($this->stateFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }
}

