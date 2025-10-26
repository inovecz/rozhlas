<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BroadcastPlaylistItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessExceptionInterface;
use Symfony\Component\Process\Process;

class PlaylistAudioPlayer
{
    private string $storageRoot;

    /** @var array<int, string> */
    private array $storageFallbacks;

    /** @var array<int, string> */
    private array $extensions;

    private string $binary;

    /** @var array<int, string> */
    private array $arguments;

    private ?float $timeout;

    private int $defaultGapMs;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('broadcast.playlist', []);

        $this->storageRoot = (string) Arr::get($config, 'storage_root', storage_path('app/recordings'));
        $this->storageFallbacks = array_values(array_filter(array_map(
            static fn ($value): string => (string) $value,
            Arr::get($config, 'storage_fallbacks', [])
        )));

        $extensions = Arr::get($config, 'supported_extensions', ['mp3', 'wav', 'ogg', 'flac']);
        $this->extensions = array_values(array_filter(array_map(
            static fn ($extension): string => ltrim(strtolower((string) $extension), '.')
                ?: 'mp3',
            is_array($extensions) ? $extensions : (array) $extensions
        )));

        $player = Arr::get($config, 'player', []);
        $this->binary = (string) Arr::get($player, 'binary', 'ffmpeg');
        $arguments = Arr::get($player, 'arguments', [
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            '{input}',
            '-vn',
            '-f',
            'alsa',
            'default',
        ]);
        $this->arguments = array_map(static fn ($value): string => (string) $value, $arguments);
        $timeout = Arr::get($player, 'timeout');
        $this->timeout = $timeout !== null ? (float) $timeout : null;

        $this->defaultGapMs = (int) Arr::get($config, 'default_gap_ms', 250);
    }

    public function play(BroadcastPlaylistItem $item): PlaylistPlaybackResult
    {
        $inputPath = $this->resolveInputPath($item);
        if ($inputPath === null) {
            return PlaylistPlaybackResult::failure('file_missing', [
                'recording_id' => $item->recording_id,
            ]);
        }

        $command = $this->buildCommand($item, $inputPath);
        $start = microtime(true);

        try {
            $timeout = $this->timeout !== null && $this->timeout > 0 ? $this->timeout : null;
            $process = new Process($command, base_path(), null, null, $timeout);
            $process->run();
        } catch (ProcessExceptionInterface $exception) {
            Log::error('Playlist playback process failed to start', [
                'recording_id' => $item->recording_id,
                'error' => $exception->getMessage(),
            ]);

            return PlaylistPlaybackResult::failure('process_start_failed', [
                'recording_id' => $item->recording_id,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Unexpected playlist playback error', [
                'recording_id' => $item->recording_id,
                'error' => $exception->getMessage(),
            ]);

            return PlaylistPlaybackResult::failure('process_exception', [
                'recording_id' => $item->recording_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $duration = microtime(true) - $start;
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();

        if (!$process->isSuccessful()) {
            Log::warning('Playlist playback process exited with error', [
                'recording_id' => $item->recording_id,
                'exit_code' => $process->getExitCode(),
                'stderr' => $errorOutput,
            ]);

            return PlaylistPlaybackResult::failure('process_failed', [
                'recording_id' => $item->recording_id,
                'exit_code' => $process->getExitCode(),
                'stderr' => $errorOutput,
                'stdout' => $output,
                'duration_seconds' => $duration,
            ]);
        }

        return PlaylistPlaybackResult::success([
            'recording_id' => $item->recording_id,
            'stdout' => $output,
            'stderr' => $errorOutput,
            'exit_code' => $process->getExitCode(),
            'duration_seconds' => $duration,
        ]);
    }

    public function calculateGapMilliseconds(BroadcastPlaylistItem $item): int
    {
        return (int) ($item->gap_ms ?? $this->defaultGapMs);
    }

    private function resolveInputPath(BroadcastPlaylistItem $item): ?string
    {
        $metadata = $item->metadata ?? [];

        $candidates = [
            Arr::get($metadata, 'absolute_path'),
            Arr::get($metadata, 'file_path'),
            Arr::get($metadata, 'path'),
            Arr::get($metadata, 'storage_path'),
            Arr::get($metadata, 'file.storage_path'),
            Arr::get($metadata, 'file.path'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $normalized = $this->normalizePath($candidate);
            if ($normalized !== null && is_file($normalized)) {
                return $normalized;
            }
        }

        $fallbacks = array_merge([$this->storageRoot], $this->storageFallbacks);
        foreach ($fallbacks as $root) {
            $rootPath = $this->normalizePath($root);
            if ($rootPath === null) {
                continue;
            }

            foreach ($this->extensions as $extension) {
                $path = $rootPath . DIRECTORY_SEPARATOR . $item->recording_id;
                if ($extension !== '') {
                    $path .= '.' . $extension;
                }
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function buildCommand(BroadcastPlaylistItem $item, string $inputPath): array
    {
        $metadata = $item->metadata ?? [];
        $playerOverrides = is_array($metadata) ? Arr::get($metadata, 'player', []) : [];

        $binary = (string) Arr::get($playerOverrides, 'binary', $this->binary);
        $command = [$binary];

        $arguments = Arr::get($playerOverrides, 'arguments', $this->arguments);
        $arguments = array_map(static fn ($value): string => (string) $value, $arguments);
        if ($item->gain !== null) {
            $gain = (float) $item->gain;
            $arguments = $this->injectVolumeFilter($arguments, $gain);
        }

        foreach ($arguments as $argument) {
            $command[] = str_replace(
                ['{input}', '{recording_id}'],
                [$inputPath, (string) $item->recording_id],
                $argument
            );
        }

        return $command;
    }

    /**
     * @param array<int, string> $arguments
     * @return array<int, string>
     */
    private function injectVolumeFilter(array $arguments, float $gainDb): array
    {
        $hasFilterArgument = false;
        foreach ($arguments as $value) {
            if (str_contains($value, '{volume_filter}')) {
                $hasFilterArgument = true;
                break;
            }
        }

        if ($hasFilterArgument) {
            return array_map(static function (string $value) use ($gainDb): string {
                if (!str_contains($value, '{volume_filter}')) {
                    return $value;
                }

                return str_replace('{volume_filter}', sprintf('volume=%sdB', $gainDb), $value);
            }, $arguments);
        }

        return array_merge(
            array_slice($arguments, 0, -2),
            ['-filter:a', sprintf('volume=%sdB', $gainDb)],
            array_slice($arguments, -2)
        );
    }

    private function normalizePath(string $path): ?string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '~')) {
            $home = (string) getenv('HOME');
            $trimmed = $home . substr($trimmed, 1);
        }
        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }
        if (preg_match('/^[A-Za-z]:\\\\/', $trimmed) === 1) {
            return $trimmed;
        }

        return storage_path('app/' . ltrim($trimmed, '/'));
    }
}
