<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ControlTabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ControlTabApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_button_9_triggers_start_via_http(): void
    {
        config([
            'control_tab.default_location_group_id' => 8,
            'control_tab.general_zone' => 120,
        ]);

        $service = Mockery::mock(ControlTabService::class);
        $service->shouldReceive('handleButtonPress')
            ->once()
            ->with(9, Mockery::type('array'))
            ->andReturn([
                'status' => 'ok',
                'session' => ['id' => 'from-feature-test'],
            ]);
        $this->app->instance(ControlTabService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
            'button_id' => 9,
            'device_id' => 'ct-001',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'status' => 'ok',
                'handled_as' => 'button',
                'action' => 'ack',
            ]);
    }
}
