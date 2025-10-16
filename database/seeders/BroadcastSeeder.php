<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Models\BroadcastSession;
use App\Models\GsmWhitelistEntry;
use App\Models\JsvvSequence;
use App\Models\JsvvSequenceItem;
use Illuminate\Database\Seeder;

class BroadcastSeeder extends Seeder
{
    public function run(): void
    {
        if (BroadcastSession::query()->exists()) {
            return;
        }

        BroadcastSession::create([
            'source' => 'seeded-demo',
            'route' => [1, 2, 3],
            'zones' => [22],
            'options' => ['note' => 'Demo session'],
            'status' => 'stopped',
            'started_at' => now()->subDay(),
            'stopped_at' => now()->subDay()->addMinutes(5),
            'python_response' => ['status' => 'ok'],
        ]);

        $playlist = BroadcastPlaylist::create([
            'status' => 'queued',
            'route' => [101],
            'zones' => [55],
            'options' => ['purpose' => 'Demo playlist'],
        ]);

        BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 0,
            'recording_id' => 'demo-track-1',
            'duration_seconds' => 120,
            'metadata' => ['title' => 'Demo Track'],
        ]);

        BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 1,
            'recording_id' => 'demo-track-2',
            'duration_seconds' => 90,
            'metadata' => ['title' => 'Another Demo'],
        ]);

        GsmWhitelistEntry::firstOrCreate([
            'number' => '+420123456789',
        ], [
            'label' => 'Demo Caller',
            'priority' => 'high',
        ]);

        $sequence = JsvvSequence::create([
            'items' => [
                ['slot' => 1, 'category' => 'verbal', 'voice' => 'male'],
                ['slot' => 2, 'category' => 'siren'],
            ],
            'options' => ['priority' => 'P2'],
            'priority' => 'P2',
            'status' => 'planned',
        ]);

        foreach ($sequence->items as $index => $item) {
            JsvvSequenceItem::create([
                'sequence_id' => $sequence->id,
                'position' => $index,
                'category' => $item['category'],
                'slot' => $item['slot'],
                'voice' => $item['voice'] ?? null,
                'repeat' => $item['repeat'] ?? 1,
                'metadata' => $item,
            ]);
        }
    }
}
