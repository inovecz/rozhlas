<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ProcessRecordingPlaylist;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Models\StreamTelemetryEntry;
use App\Services\PlaylistAudioPlayer;
use App\Services\PlaylistPlaybackResult;
use App\Services\StreamOrchestrator;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessRecordingPlaylistTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_playlist_job_completes_successfully(): void
    {
        $playlist = BroadcastPlaylist::create([
            'status' => 'queued',
        ]);

        BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 0,
            'recording_id' => 'demo-1',
        ]);

        BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 1,
            'recording_id' => 'demo-2',
        ]);

        /** @var MockInterface&StreamOrchestrator $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')->once()->andReturn(['status' => 'running']);
        $orchestrator->shouldReceive('stop')->once()->with('playlist_completed');

        /** @var MockInterface&PlaylistAudioPlayer $player */
        $player = Mockery::mock(PlaylistAudioPlayer::class);
        $player->shouldReceive('play')->twice()->andReturn(PlaylistPlaybackResult::success());
        $player->shouldReceive('calculateGapMilliseconds')->twice()->andReturn(0);

        $job = new ProcessRecordingPlaylist($playlist->id);
        $job->handle($orchestrator, $player);

        $this->assertSame('completed', $playlist->fresh()->status);
        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'playlist_completed',
            'playlist_id' => $playlist->id,
        ]);
    }

    public function test_playlist_job_handles_playback_failure(): void
    {
        $playlist = BroadcastPlaylist::create([
            'status' => 'queued',
        ]);

        BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 0,
            'recording_id' => 'demo-1',
        ]);

        /** @var MockInterface&StreamOrchestrator $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')->once()->andReturn(['status' => 'running']);
        $orchestrator->shouldReceive('stop')->once()->with('playlist_failed');

        /** @var MockInterface&PlaylistAudioPlayer $player */
        $player = Mockery::mock(PlaylistAudioPlayer::class);
        $player->shouldReceive('play')->once()->andReturn(PlaylistPlaybackResult::failure('process_failed'));
        $player->shouldReceive('calculateGapMilliseconds')->never();

        $job = new ProcessRecordingPlaylist($playlist->id);
        $job->handle($orchestrator, $player);

        $this->assertSame('failed', $playlist->fresh()->status);
        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'playlist_item_failed',
            'playlist_id' => $playlist->id,
        ]);
    }
}
