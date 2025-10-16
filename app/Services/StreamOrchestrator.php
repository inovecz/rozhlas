<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessRecordingPlaylist;
use App\Libraries\PythonClient;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Models\BroadcastSession;
use App\Models\StreamTelemetryEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class StreamOrchestrator extends Service
{
    public function __construct(private readonly PythonClient $client = new PythonClient())
    {
        parent::__construct();
    }

    public function start(array $payload): array
    {
        $active = BroadcastSession::query()->where('status', 'running')->latest('started_at')->first();
        if ($active !== null) {
            return $active->toArray();
        }

        $route = Arr::get($payload, 'route', []);
        $zones = Arr::get($payload, 'zones', []);
        $options = Arr::get($payload, 'options', []);

        $response = $this->client->startStream(route: $route, zones: $zones);

        $session = BroadcastSession::create([
            'source' => Arr::get($payload, 'source', 'unknown'),
            'route' => $route,
            'zones' => $zones,
            'options' => $options,
            'status' => 'running',
            'started_at' => now(),
            'python_response' => $response,
        ]);

        $this->recordTelemetry([
            'type' => 'stream_started',
            'session_id' => $session->id,
            'payload' => [
                'source' => $session->source,
            ],
        ]);

        return $session->fresh()->toArray();
    }

    public function stop(?string $reason = null): array
    {
        $session = BroadcastSession::query()->where('status', 'running')->latest('started_at')->first();
        if ($session === null) {
            return [
                'status' => 'idle',
                'message' => 'No active session',
            ];
        }

        $response = $this->client->stopStream();

        $session->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'stop_reason' => $reason,
            'python_response' => $response,
        ]);

        $this->recordTelemetry([
            'type' => 'stream_stopped',
            'session_id' => $session->id,
            'payload' => [
                'reason' => $reason,
            ],
        ]);

        return $session->fresh()->toArray();
    }

    public function getStatus(): array
    {
        $session = BroadcastSession::query()->latest('created_at')->first();
        $status = $this->client->getStatusRegisters();
        $device = $this->client->getDeviceInfo();

        return [
            'session' => $session?->toArray(),
            'status' => $status,
            'device' => $device,
        ];
    }

    public function listSources(): array
    {
        $sources = [
            ['id' => 'mic', 'label' => 'Live Microphone'],
            ['id' => 'recording', 'label' => 'Recorded Playlist'],
            ['id' => 'gsm', 'label' => 'GSM Module'],
            ['id' => 'radio', 'label' => 'FM Radio'],
            ['id' => 'jsvv', 'label' => 'JSVV Automation'],
        ];

        if (config('broadcast.alza_mixer')) {
            $sources[] = ['id' => 'mixer', 'label' => 'Alza Mixer Bridge'];
        }

        return $sources;
    }

    public function enqueuePlaylist(array $items, array $route, array $zones, array $options = []): array
    {
        return DB::transaction(function () use ($items, $route, $zones, $options): array {
            $playlist = BroadcastPlaylist::create([
                'route' => $route,
                'zones' => $zones,
                'options' => $options,
                'status' => 'queued',
            ]);

            foreach ($items as $index => $item) {
                BroadcastPlaylistItem::create([
                    'playlist_id' => $playlist->id,
                    'position' => $index,
                    'recording_id' => (string) Arr::get($item, 'id'),
                    'duration_seconds' => Arr::get($item, 'durationSeconds'),
                    'gain' => Arr::get($item, 'gain'),
                    'gap_ms' => Arr::get($item, 'gapMs'),
                    'metadata' => $item,
                ]);
            }

            Bus::dispatch(new ProcessRecordingPlaylist($playlist->id));

            $this->recordTelemetry([
                'type' => 'playlist_queued',
                'playlist_id' => $playlist->id,
                'payload' => ['count' => count($items)],
            ]);

            return $playlist->load('items')->toArray();
        });
    }

    public function cancelPlaylist(string $playlistId): array
    {
        $playlist = BroadcastPlaylist::query()->with('items')->find($playlistId);
        if ($playlist === null) {
            return [
                'status' => 'not_found',
                'playlistId' => $playlistId,
            ];
        }

        $playlist->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->recordTelemetry([
            'type' => 'playlist_cancelled',
            'playlist_id' => $playlist->id,
            'payload' => [],
        ]);

        return $playlist->fresh()->toArray();
    }

    public function getPlaylist(string $playlistId): ?array
    {
        return BroadcastPlaylist::query()->with('items')->find($playlistId)?->toArray();
    }

    public function updatePlaylist(string $playlistId, array $attributes): void
    {
        BroadcastPlaylist::query()->whereKey($playlistId)->update($attributes + ['updated_at' => now()]);
    }

    public function recordTelemetry(array $entry): void
    {
        StreamTelemetryEntry::create([
            'type' => $entry['type'],
            'session_id' => $entry['session_id'] ?? null,
            'playlist_id' => $entry['playlist_id'] ?? null,
            'payload' => $entry['payload'] ?? [],
            'recorded_at' => now(),
        ]);
    }

    public function telemetry(?string $since = null): array
    {
        return StreamTelemetryEntry::query()
            ->when($since, fn ($query) => $query->where('recorded_at', '>=', $since))
            ->latest('recorded_at')
            ->limit(500)
            ->get()
            ->toArray();
    }
}
