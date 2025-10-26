<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BroadcastPlaylist;
use App\Models\BroadcastPlaylistItem;
use App\Services\PlaylistAudioPlayer;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class PlaylistAudioPlayerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $filesystem = new Filesystem();
        $this->tempDir = storage_path('app/test_recordings_' . Str::random(6));
        $filesystem->deleteDirectory($this->tempDir);
        $filesystem->makeDirectory($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_play_returns_failure_when_file_missing(): void
    {
        $playlist = BroadcastPlaylist::create([
            'status' => 'queued',
        ]);

        $item = BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 0,
            'recording_id' => 'missing-track',
        ]);

        $player = new PlaylistAudioPlayer([
            'storage_root' => $this->tempDir,
            'supported_extensions' => ['txt'],
            'player' => [
                'binary' => PHP_BINARY,
                'arguments' => ['-r', 'exit(0);', '{input}'],
                'timeout' => 2,
            ],
        ]);

        $result = $player->play($item);

        $this->assertFalse($result->success);
        $this->assertSame('file_missing', $result->status);
    }

    public function test_play_executes_command_when_file_exists(): void
    {
        $playlist = BroadcastPlaylist::create([
            'status' => 'queued',
        ]);

        $item = BroadcastPlaylistItem::create([
            'playlist_id' => $playlist->id,
            'position' => 0,
            'recording_id' => 'demo-track',
        ]);

        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'demo-track.txt';
        file_put_contents($filePath, 'hello world');

        $player = new PlaylistAudioPlayer([
            'storage_root' => $this->tempDir,
            'supported_extensions' => ['txt'],
            'player' => [
                'binary' => PHP_BINARY,
                'arguments' => [
                    '-r',
                    'if (!file_exists($argv[1])) { exit(3); }',
                    '{input}',
                ],
                'timeout' => 5,
            ],
            'default_gap_ms' => 150,
        ]);

        $result = $player->play($item);

        $this->assertTrue($result->success);
        $this->assertSame('ok', $result->status);
        $this->assertArrayHasKey('duration_seconds', $result->context);
        $this->assertGreaterThanOrEqual(0.0, $result->context['duration_seconds']);
        $this->assertSame(150, $player->calculateGapMilliseconds($item));
    }
}
