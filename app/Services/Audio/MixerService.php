<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use App\Models\Recording;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MixerService
{
    private bool $enabled;
    private string $card;
    private string $amixerBinary;
    private string $alsactlBinary;
    private string $profileDirectory;
    private string $arecordBinary;
    private string $captureDevice;
    private string $captureFormat;
    private string $recordingDirectory;
    private string $captureStateFile;

    public function __construct(
        private readonly AudioIoService $audio = new AudioIoService(),
        ?array $config = null,
    ) {
        $config = $config ?? config('audio', []);
        $this->enabled = $this->normalizeBool(
            Arr::get($config, 'enabled'),
            (bool) config('broadcast.mixer.enabled', true)
        );
        $cardFromConfig = (string) Arr::get($config, 'card', '');
        $this->card = $cardFromConfig !== ''
            ? $cardFromConfig
            : '0';

        $binaries = Arr::get($config, 'binaries', []);
        $this->amixerBinary = (string) Arr::get($binaries, 'amixer', 'amixer');
        $this->alsactlBinary = (string) env('AUDIO_ALSACTL_BINARY', 'alsactl');
        $this->arecordBinary = (string) Arr::get($binaries, 'arecord', 'arecord');
        $this->profileDirectory = storage_path('app/alsa-profiles');
        $captureConfig = Arr::get($config, 'capture', []);
        $this->captureDevice = (string) Arr::get($captureConfig, 'device', 'default');
        $this->captureFormat = (string) Arr::get($captureConfig, 'format', 'cd');
        $this->recordingDirectory = (string) Arr::get($captureConfig, 'directory', storage_path('app/public/recordings'));
        $this->captureStateFile = storage_path('app/audio-capture-state.json');
    }

    /**
     * Switch active mixer input immediately. Returns current mixer status snapshot.
     *
     * @return array<string, mixed>
     */
    public function selectInput(string $identifier): array
    {
        $identifier = strtolower($identifier);

        if (!$this->enabled) {
            Log::debug('Mixer input change skipped because mixer is disabled.', [
                'identifier' => $identifier,
            ]);
            return $this->audio->status();
        }

        if (!$this->ensureCardAvailable()) {
            Log::error('Mixer input change skipped because ALSA card is unavailable.', [
                'identifier' => $identifier,
                'card' => $this->card,
            ]);

            return $this->audio->status();
        }

        $logContext = [
            'identifier' => $identifier,
            'card' => $this->card,
        ];

        $this->logAction('select_input.requested', $logContext);

        $profilePath = $this->resolveProfilePath($identifier);

        if ($profilePath !== null) {
            try {
                $this->restoreProfile($profilePath);
                $status = $this->audio->status();
                $this->logAction('select_input.restored', $logContext + ['profile' => $profilePath]);
                return $status;
            } catch (\Throwable $exception) {
                $this->logAction('select_input.profile_failed', $logContext + [
                    'profile' => $profilePath,
                    'error' => $exception->getMessage(),
                ], 'error');
                Log::warning('ALSA profile restore failed, falling back to amixer controls.', [
                    'identifier' => $identifier,
                    'profile' => $profilePath,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            $status = $this->audio->setInput($identifier);
            $this->logAction('select_input.amixer_applied', $logContext);
            return $status;
        } catch (InvalidArgumentException $exception) {
            $this->logAction('select_input.invalid', $logContext + ['error' => $exception->getMessage()], 'error');
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->logAction('select_input.failed', $logContext + ['error' => $exception->getMessage()], 'error');
            Log::error('Mixer input change failed (runtime).', [
                'identifier' => $identifier,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logAction('select_input.failed', $logContext + ['error' => $exception->getMessage()], 'error');
            Log::error('Mixer input change failed.', [
                'identifier' => $identifier,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    public function getCard(): string
    {
        return $this->card;
    }

    public function getAmixerBinary(): string
    {
        return $this->amixerBinary;
    }

    public function getAlsactlBinary(): string
    {
        return $this->alsactlBinary;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function startCapture(?string $source = null): Recording
    {
        $filesystem = app(Filesystem::class);
        if ($filesystem->exists($this->captureStateFile)) {
            $state = $this->readCaptureState();
            if ($state !== null && $this->isProcessRunning($state['pid'] ?? null)) {
                throw new RuntimeException('Záznam již běží. Nejprve jej zastavte.');
            }
        }

        if (!$filesystem->isDirectory($this->recordingDirectory)) {
            $filesystem->makeDirectory($this->recordingDirectory, 0755, true);
        }

        $filename = sprintf('%s.wav', Str::uuid()->toString());
        $absolutePath = $this->recordingDirectory . DIRECTORY_SEPARATOR . $filename;
        $relativePath = 'recordings/' . $filename;
        $startedAt = now();

        $command = sprintf(
            'nohup %s -D %s -f %s -t wav %s > /dev/null 2>&1 & echo $!',
            escapeshellcmd($this->arecordBinary),
            escapeshellarg($this->captureDevice === '' ? 'default' : $this->captureDevice),
            escapeshellarg($this->captureFormat === '' ? 'cd' : $this->captureFormat),
            escapeshellarg($absolutePath)
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Spuštění záznamu selhalo: %s',
                trim($process->getErrorOutput() ?: $process->getOutput())
            ));
        }

        $pid = (int) trim($process->getOutput());
        if ($pid <= 0) {
            throw new RuntimeException('Nepodařilo se zjistit PID procesu záznamu.');
        }

        $recording = Recording::create([
            'path' => $relativePath,
            'source' => $source,
            'started_at' => $startedAt,
        ]);

        $this->writeCaptureState([
            'pid' => $pid,
            'recording_id' => $recording->getId(),
            'path' => $absolutePath,
            'started_at' => $startedAt->toIso8601String(),
        ]);

        $this->logAction('capture.started', [
            'pid' => $pid,
            'recording_id' => $recording->getId(),
            'path' => $absolutePath,
        ]);

        return $recording;
    }

    public function stopCapture(): Recording
    {
        $state = $this->readCaptureState();
        if ($state === null) {
            throw new RuntimeException('Žádný aktivní záznam neběží.');
        }

        $pid = (int) ($state['pid'] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Neplatný PID procesu záznamu.');
        }

        $this->terminateProcess($pid);

        $endedAt = now();
        $recordingId = $state['recording_id'] ?? null;
        $recording = $recordingId !== null ? Recording::query()->find($recordingId) : null;
        if ($recording === null) {
            $recording = new Recording([
                'path' => $this->relativeRecordingPath($state['path'] ?? ''),
                'source' => null,
                'started_at' => isset($state['started_at']) ? Carbon::parse($state['started_at']) : null,
            ]);
        }

        $duration = $this->calculateDurationSeconds($recording->started_at, $endedAt);

        $recording->forceFill([
            'ended_at' => $endedAt,
            'duration_s' => $duration,
        ]);
        $recording->save();

        $this->clearCaptureState();
        $this->logAction('capture.stopped', [
            'pid' => $pid,
            'recording_id' => $recording->getId(),
            'duration_s' => $duration,
        ]);

        return $recording;
    }

    private function ensureCardAvailable(): bool
    {
        if ($this->card === '' || $this->card === 'default') {
            return true;
        }

        $cardIdVariants = [
            $this->card,
            preg_replace('/^card/i', '', $this->card) ?: $this->card,
        ];

        foreach (array_filter(array_unique($cardIdVariants)) as $cardId) {
            $asoundPath = sprintf('/proc/asound/card%s', $cardId);
            if (is_dir($asoundPath)) {
                return true;
            }

            $longPath = sprintf('/proc/asound/%s', $cardId);
            if (is_dir($longPath)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeBool(mixed $value, bool $default = true): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function resolveProfilePath(string $identifier): ?string
    {
        $filesystem = app(Filesystem::class);
        if (!$filesystem->isDirectory($this->profileDirectory)) {
            return null;
        }

        $candidates = array_unique([
            $identifier,
            $this->resolveAliasFromConfig($identifier),
        ]);

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $path = $this->profileDirectory . DIRECTORY_SEPARATOR . $candidate . '.profile';
            if ($filesystem->isFile($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveAliasFromConfig(string $identifier): ?string
    {
        $inputs = Arr::get(config('audio.inputs', []), 'items', []);
        if (!is_array($inputs) || $inputs === []) {
            return null;
        }

        $definition = $inputs[$identifier] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        $alias = $definition['alias_of'] ?? null;
        if (is_string($alias) && $alias !== '') {
            return $alias;
        }

        return null;
    }

    private function restoreProfile(string $profilePath): void
    {
        $command = [$this->alsactlBinary, '--file', $profilePath, 'restore'];
        if ($this->card !== '') {
            $command[] = $this->card;
        }

        $process = new Process($command);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            throw new RuntimeException(sprintf('Obnovení ALSA profilu selhalo: %s', $exception->getMessage()), 0, $exception);
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function logAction(string $event, array $context = [], string $level = 'info'): void
    {
        $logger = Log::channel('mixer');
        $context['timestamp'] = now()->toIso8601String();

        $logger->$level($event, $context);
    }

    private function writeCaptureState(array $state): void
    {
        file_put_contents($this->captureStateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function readCaptureState(): ?array
    {
        if (!file_exists($this->captureStateFile)) {
            return null;
        }

        $raw = file_get_contents($this->captureStateFile);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function clearCaptureState(): void
    {
        if (file_exists($this->captureStateFile)) {
            @unlink($this->captureStateFile);
        }
    }

    private function isProcessRunning(?int $pid): bool
    {
        if ($pid === null || $pid <= 0) {
            return false;
        }

        return function_exists('posix_kill') ? @posix_kill($pid, 0) : file_exists("/proc/{$pid}");
    }

    private function terminateProcess(int $pid): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            usleep(200000);
            if ($this->isProcessRunning($pid)) {
                @posix_kill($pid, SIGKILL);
            }
            return;
        }

        $process = Process::fromShellCommandline(sprintf('kill %d', $pid));
        $process->setTimeout(5);
        $process->run();
    }

    private function calculateDurationSeconds(?CarbonInterface $startedAt, CarbonInterface $endedAt): ?int
    {
        if ($startedAt === null) {
            return null;
        }

        return max(0, $endedAt->diffInSeconds($startedAt));
    }

    private function relativeRecordingPath(?string $path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        if (str_starts_with($path, $this->recordingDirectory)) {
            return 'recordings/' . ltrim(substr($path, strlen($this->recordingDirectory)), DIRECTORY_SEPARATOR);
        }

        return $path;
    }
}
