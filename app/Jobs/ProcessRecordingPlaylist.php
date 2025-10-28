<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\BroadcastLockedException;
use App\Models\BroadcastPlaylist;
use App\Models\StreamTelemetryEntry;
use App\Services\PlaylistAudioPlayer;
use App\Services\StreamOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ProcessRecordingPlaylist implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Recorded playlists can span several minutes.
     */
    public int $timeout = 900;

    /**
     * Retries are orchestrated manually via requeue logic.
     */
    public int $tries = 1;

    public function __construct(private readonly string $playlistId)
    {
    }

    public function handle(StreamOrchestrator $orchestrator, PlaylistAudioPlayer $player): void
    {
        $playlist = BroadcastPlaylist::query()->with('items')->find($this->playlistId);
        if ($playlist === null) {
            return;
        }

        if ($playlist->status === 'cancelled') {
            return;
        }

        $selection = Arr::get($playlist->options ?? [], '_selection', []);

        if ($playlist->status === 'queued') {
            // Playlist queued but not yet running; mark telemetry for start attempt.
            StreamTelemetryEntry::create([
                'type' => 'playlist_attempt_start',
                'playlist_id' => $playlist->id,
                'payload' => [
                    'item_count' => $playlist->items->count(),
                ],
                'recorded_at' => now(),
            ]);
        }

        try {
            $orchestrator->start([
                'source' => 'recorded_playlist',
                'route' => Arr::get($selection, 'route', $playlist->route ?? []),
                'locations' => Arr::get($selection, 'locations', []),
                'nests' => Arr::get($selection, 'nests', []),
                'options' => $playlist->options ?? [],
            ]);
        } catch (BroadcastLockedException $exception) {
            $playlist->update([
                'status' => 'queued',
                'started_at' => null,
            ]);
            $this->release(10);
            return;
        }

        $playlist->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        StreamTelemetryEntry::create([
            'type' => 'playlist_started',
            'playlist_id' => $playlist->id,
            'recorded_at' => now(),
            'payload' => [
                'recording_count' => $playlist->items()->count(),
            ],
        ]);

        foreach ($playlist->items()->orderBy('position')->get() as $item) {
            $freshPlaylist = $playlist->fresh();
            if ($freshPlaylist->status === 'cancelled') {
                $orchestrator->stop('playlist_cancelled');
                StreamTelemetryEntry::create([
                    'type' => 'playlist_cancelled_runtime',
                    'playlist_id' => $playlist->id,
                    'payload' => [
                        'position' => $item->position,
                    ],
                    'recorded_at' => now(),
                ]);
                return;
            }

            if ($freshPlaylist->status === 'queued') {
                $orchestrator->stop('playlist_requeued');
                return;
            }

            StreamTelemetryEntry::create([
                'type' => 'playlist_item_started',
                'playlist_id' => $playlist->id,
                'payload' => [
                    'position' => $item->position,
                    'recording_id' => $item->recording_id,
                ],
                'recorded_at' => now(),
            ]);

            sleep(min((int) ($item->duration_seconds ?? 1), 5));

            $result = $player->play($item);

            if (!$result->success) {
                StreamTelemetryEntry::create([
                    'type' => 'playlist_item_failed',
                    'playlist_id' => $playlist->id,
                    'payload' => array_merge([
                        'position' => $item->position,
                        'recording_id' => $item->recording_id,
                    ], $result->context),
                    'recorded_at' => now(),
                ]);

                $playlist->update([
                    'status' => 'failed',
                    'completed_at' => null,
                ]);

                Log::warning('Playlist item playback failed', [
                    'playlist_id' => $playlist->id,
                    'recording_id' => $item->recording_id,
                    'status' => $result->status,
                    'context' => $result->context,
                ]);

                $orchestrator->stop('playlist_failed');

                return;
            }

            StreamTelemetryEntry::create([
                'type' => 'playlist_item_finished',
                'playlist_id' => $playlist->id,
                'payload' => array_merge([
                    'position' => $item->position,
                    'recording_id' => $item->recording_id,
                ], $result->context),
                'recorded_at' => now(),
            ]);

            $gapMs = $player->calculateGapMilliseconds($item);
            if ($gapMs > 0) {
                usleep($gapMs * 1000);
                StreamTelemetryEntry::create([
                    'type' => 'playlist_gap_elapsed',
                    'playlist_id' => $playlist->id,
                    'payload' => [
                        'position' => $item->position,
                        'gap_ms' => $gapMs,
                    ],
                    'recorded_at' => now(),
                ]);
            }
        }

        $orchestrator->stop('playlist_completed');

        $playlist->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        StreamTelemetryEntry::create([
            'type' => 'playlist_completed',
            'playlist_id' => $playlist->id,
            'payload' => [],
            'recorded_at' => now(),
        ]);
    }
}
