<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\LocationGroup;
use App\Services\ControlTabBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ControlTabEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function testLocalitiesEndpointReturnsPlainTextList(): void
    {
        LocationGroup::query()->create(['name' => 'Alfa']);
        LocationGroup::query()->create(['name' => 'Bravo']);

        $response = $this->get('/api/ct/localities');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame("Alfa\nBravo", $response->getContent());
    }

    public function testPushFieldsTriggersBridge(): void
    {
        $mock = Mockery::mock(ControlTabBridge::class);
        $this->app->instance(ControlTabBridge::class, $mock);

        $mock
            ->shouldReceive('sendFields')
            ->once()
            ->with(
                ['2' => '00:00'],
                Mockery::on(static function (array $options): bool {
                    return ($options['screen'] ?? null) === 3
                        && ($options['panel'] ?? null) === 1
                        && ($options['switch_panel'] ?? false) === false;
                })
            )
            ->andReturn([
                'command' => ['python3', 'ct_listener.py'],
                'exit_code' => 0,
                'output' => 'dry-run',
                'error_output' => '',
                'duration_ms' => 5,
            ]);

        $response = $this->postJson('/api/ct/push-fields', [
            'fields' => ['2' => '00:00'],
        ]);

        $response->assertOk();
        $response->assertJson(["status" => 'ok', 'exit_code' => 0]);
    }
}

