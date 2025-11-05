<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audio\AlsamixerService;
use App\Services\ModbusControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LiveBroadcastApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_start_invokes_alsa_and_modbus(): void
    {
        $alsamixer = Mockery::mock(AlsamixerService::class);
        $alsamixer->shouldReceive('selectInput')
            ->once()
            ->with('microphone', 75.0)
            ->andReturn(true);
        $this->app->instance(AlsamixerService::class, $alsamixer);

        $modbus = Mockery::mock(ModbusControlService::class);
        $modbus->shouldReceive('startStream')
            ->once()
            ->with([100], [])
            ->andReturn(['exit_code' => 0]);
        $this->app->instance(ModbusControlService::class, $modbus);

        $response = $this->postJson('/api/live-broadcast/start', [
            'source' => 'microphone',
            'zones' => [100],
            'volume' => 75,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'status' => 'ok',
            ]);
    }

    public function test_stop_invokes_modbus_stop(): void
    {
        $modbus = Mockery::mock(ModbusControlService::class);
        $modbus->shouldReceive('stopStream')
            ->once()
            ->andReturn(['exit_code' => 0]);
        $this->app->instance(ModbusControlService::class, $modbus);

        $response = $this->postJson('/api/live-broadcast/stop');

        $response->assertOk()
            ->assertJsonFragment([
                'status' => 'ok',
            ]);
    }

    public function test_runtime_input_updates_alsa(): void
    {
        $alsamixer = Mockery::mock(AlsamixerService::class);
        $alsamixer->shouldReceive('selectInput')
            ->once()
            ->with('microphone', 60.0)
            ->andReturn(true);
        $this->app->instance(AlsamixerService::class, $alsamixer);

        $response = $this->postJson('/api/live-broadcast/runtime', [
            'source' => 'microphone',
            'volume' => 60,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'status' => 'ok',
            ]);
    }
}
