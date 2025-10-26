<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StreamTelemetryEntry;
use App\Services\ControlTabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ControlTabControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_button_press_returns_ack_response(): void
    {
        $service = Mockery::mock(ControlTabService::class);
        $service->shouldReceive('handleButtonPress')
            ->once()
            ->with(5, Mockery::any())
            ->andReturn(['status' => 'ok', 'message' => 'Started']);

        $this->app->instance(ControlTabService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
            'screen' => 1,
            'panel' => 2,
            'button_id' => 5,
            'raw' => '<<<:1:2:2=5>>AA<<<',
        ]);

        $response->assertOk();
        $response->assertJson([
            'action' => 'ack',
            'ack' => [
                'screen' => 1,
                'panel' => 2,
                'eventType' => 2,
                'status' => 1,
            ],
        ]);

        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'control_tab_button_pressed',
        ]);
    }

    public function test_text_request_returns_text_action(): void
    {
        $service = Mockery::mock(ControlTabService::class);
        $service->shouldReceive('handleTextRequest')
            ->once()
            ->with(3)
            ->andReturn(['status' => 'ok', 'field_id' => 3, 'text' => 'Ready']);

        $this->app->instance(ControlTabService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'text_field_request',
            'screen' => 1,
            'panel' => 1,
            'field_id' => 3,
            'raw' => '<<<:1:1:3=?3?>>AA<<<',
        ]);

        $response->assertOk();
        $response->assertJson([
            'action' => 'text',
            'text' => [
                'fieldId' => 3,
                'text' => 'Ready',
            ],
        ]);

        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'control_tab_text_field_request',
        ]);
    }
}
