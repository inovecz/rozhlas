<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\JsvvAudioGroupEnum;
use App\Enums\JsvvAudioTypeEnum;
use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use App\Services\JsvvSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_button_event_endpoint(): void
    {
        config([
            'control_tab.buttons' => [
                13 => ['action' => 'trigger_jsvv_alarm', 'button' => 2],
            ],
        ]);

        JsvvAudio::create([
            'symbol' => '1',
            'name' => 'Kolísavý tón',
            'type' => JsvvAudioTypeEnum::FILE,
            'group' => JsvvAudioGroupEnum::SIREN,
        ]);
        JsvvAlarm::create([
            'name' => 'Test Alarm',
            'sequence_1' => '1',
            'button' => 2,
        ]);

        Bus::fake();

        $service = Mockery::mock(JsvvSequenceService::class);
        $service->shouldReceive('plan')->andReturn(['id' => 'x', 'status' => 'planned']);
        $service->shouldReceive('trigger')->andReturn(['id' => 'x', 'status' => 'queued', 'queue_position' => 1]);
        $this->app->instance(JsvvSequenceService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
            'button_id' => 13,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'queued']);
    }
}
