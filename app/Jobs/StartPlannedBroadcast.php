<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BroadcastPlaylistItem;
use App\Models\File;
use App\Models\Schedule;
use App\Services\Audio\MixerService;
use App\Services\PlaylistAudioPlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StartPlannedBroadcast implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Planned broadcasts can span several minutes.
     */
    public int $timeout = 3600;

    /**
     * Retries are orchestrated manually; do not auto-retry.
     */
    public int $tries = 1;

    public function __construct(private readonly int $scheduleId)
    {
        $queue = config('broadcast.schedule.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(MixerService $mixer, PlaylistAudioPlayer $player): void
    {
        /** @var Schedule|null $schedule */
        $schedule = Schedule::query()
            ->with(['intro', 'opening', 'commons', 'closing', 'outro'])
            ->find($this->scheduleId);

        if ($schedule === null) {
            Log::warning('Planned broadcast skipped: schedule not found.', [
                'schedule_id' => $this->scheduleId,
            ]);
            return;
        }

        if ($schedule->processed_at !== null) {
            Log::info('Planned broadcast already processed, skipping.', [
                'schedule_id' => $schedule->getId(),
            ]);
            return;
        }

        $lock = Cache::lock(sprintf('schedule:%s', $schedule->getId()), 600);
        $acquired = $lock->get();
        if (!$acquired) {
            $this->release(10);
            return;
        }

        try {

            $playlistItems = $this->buildPlaylistItems($schedule);
            if ($playlistItems === []) {
                Log::warning('Planned broadcast has no playable items.', [
                    'schedule_id' => $schedule->getId(),
                ]);
                $schedule->update(['processed_at' => now()]);
                return;
            }

            $inputIdentifier = config('broadcast.schedule.input', 'file');
            if (is_string($inputIdentifier) && $inputIdentifier !== '') {
                try {
                    $mixer->selectInput($inputIdentifier);
                } catch (\Throwable $exception) {
                    Log::error('Unable to switch mixer input for planned broadcast.', [
                        'schedule_id' => $schedule->getId(),
                        'identifier' => $inputIdentifier,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $success = true;
            foreach ($playlistItems as $index => $item) {
                $result = $player->play($item);
                if (!$result->success) {
                    $success = false;
                    Log::error('Playback of planned broadcast item failed.', [
                        'schedule_id' => $schedule->getId(),
                        'item_index' => $index,
                        'recording_id' => $item->recording_id,
                        'status' => $result->status,
                        'context' => $result->context,
                    ]);
                    break;
                }

                if ($item->gap_ms !== null && $item->gap_ms > 0) {
                    usleep((int) $item->gap_ms * 1000);
                }
            }

            $resetIdentifier = config('broadcast.schedule.reset_input');
            if (is_string($resetIdentifier) && $resetIdentifier !== '') {
                try {
                    $mixer->selectInput($resetIdentifier);
                } catch (\Throwable $exception) {
                    Log::warning('Unable to reset mixer input after planned broadcast.', [
                        'schedule_id' => $schedule->getId(),
                        'identifier' => $resetIdentifier,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $schedule->update([
                'processed_at' => now(),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<int, BroadcastPlaylistItem>
     */
    private function buildPlaylistItems(Schedule $schedule): array
    {
        $items = [];

        $append = function (?File $file, string $role) use (&$items, $schedule): void {
            if ($file === null) {
                return;
            }
            $items[] = $this->createPlaylistItem($file, $role, $schedule);
        };

        $append($schedule->intro, 'intro');
        $append($schedule->opening, 'opening');

        foreach ($schedule->commons as $file) {
            $append($file, 'common');
        }

        $append($schedule->closing, 'closing');
        $append($schedule->outro, 'outro');

        return array_values($items);
    }

    private function createPlaylistItem(File $file, string $role, Schedule $schedule): BroadcastPlaylistItem
    {
        $storagePath = $file->getStoragePath();
        $absolutePath = storage_path('app/' . ltrim($storagePath, '/'));
        $metadata = $file->getMetadata() ?? [];

        $metadata['role'] = $metadata['role'] ?? $role;
        $metadata['filename'] = $metadata['filename'] ?? $file->getFilename() . '.' . $file->getExtension();
        $metadata['extension'] = $metadata['extension'] ?? $file->getExtension();
        $metadata['storage_path'] = $metadata['storage_path'] ?? $storagePath;
        $metadata['path'] = $metadata['path'] ?? $absolutePath;
        $metadata['schedule_id'] = $schedule->getId();

        $duration = $metadata['duration'] ?? Arr::get($metadata, 'duration_seconds');
        $gapMs = Arr::get($metadata, 'gap_ms', Arr::get($metadata, 'gapMs'));

        return new BroadcastPlaylistItem([
            'recording_id' => (string) $file->getId(),
            'duration_seconds' => is_numeric($duration) ? (int) $duration : null,
            'gain' => null,
            'gap_ms' => is_numeric($gapMs) ? (int) $gapMs : null,
            'metadata' => array_merge($metadata, [
                'mime_type' => $file->getMimeType(),
                'title' => $file->getName(),
                'source_path' => $absolutePath,
            ]),
        ]);
    }
}
