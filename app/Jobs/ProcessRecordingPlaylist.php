<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BroadcastPlaylist;
use App\Models\StreamTelemetryEntry;
use App\Services\StreamOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ProcessRecordingPlaylist implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $playlistId)
    {
    }

    public function handle(StreamOrchestrator $orchestrator): void
    {
        $playlist = BroadcastPlaylist::query()->with('items')->find($this->playlistId);
        if ($playlist === null) {
            return;
        }

        if ($playlist->status === 'cancelled') {
            return;
        }

        $playlist->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $orchestrator->start([
            'source' => 'recording',
            'route' => $playlist->route ?? [],
            'zones' => $playlist->zones ?? [],
            'options' => $playlist->options ?? [],
        ]);

        foreach ($playlist->items()->orderBy('position')->get() as $item) {
            $freshPlaylist = $playlist->fresh();
            if ($freshPlaylist->status === 'cancelled') {
                $orchestrator->stop('playlist_cancelled');
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

            StreamTelemetryEntry::create([
                'type' => 'playlist_item_finished',
                'playlist_id' => $playlist->id,
                'payload' => [
                    'position' => $item->position,
                ],
                'recorded_at' => now(),
            ]);
        }

        $orchestrator->stop('playlist_completed');

        $playlist->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
