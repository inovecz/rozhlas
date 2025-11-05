<?php

declare(strict_types=1);

namespace Tests\Unit;

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

    public function test_button_press_delegates_to_service(): void
    {
        $service = Mockery::mock(ControlTabService::class);
        $service->shouldReceive('handleButtonPress')
            ->once()
            ->with(9, Mockery::type('array'))
            ->andReturn([
                'status' => 'ok',
                'message' => 'Started',
            ]);

        $this->app->instance(ControlTabService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
            'button_id' => 9,
            'screen' => 1,
            'panel' => 2,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'status' => 'ok',
                'handled_as' => 'button',
                'action' => 'ack',
            ]);
    }

    public function test_legacy_button_payload_is_supported(): void
    {
        $service = Mockery::mock(ControlTabService::class);
        $service->shouldReceive('handleButtonPress')
            ->once()
            ->with(10, Mockery::type('array'))
            ->andReturn([
                'status' => 'ok',
                'message' => 'Stopped',
            ]);

        $this->app->instance(ControlTabService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
            'buttonId' => '10',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'status' => 'ok',
                'handled_as' => 'button',
            ]);
    }

    public function test_missing_button_id_returns_validation_error(): void
    {
        $service = Mockery::mock(ControlTabService::class);
        $service->shouldNotReceive('handleButtonPress');
        $this->app->instance(ControlTabService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'validation_error',
            ]);
    }
}
