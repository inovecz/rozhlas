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

    public function test_panel_loaded_event_returns_acknowledgement(): void
    {
        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'panel_loaded',
            'screen' => 1,
            'panel' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('ack.status', 1)
            ->assertJsonPath('ack.screen', 1)
            ->assertJsonPath('ack.panel', 1);
    }

    public function test_text_field_request_provides_status_summary(): void
    {
        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'text_field_request',
            'field_id' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('action', 'text')
            ->assertJsonPath('text.fieldId', 1)
            ->assertJsonPath('text.text', 'Ústředna je připravena.');
    }

    public function test_siren_button_triggers_jsvv_sequence(): void
    {
        config([
            'control_tab.buttons' => array_replace(config('control_tab.buttons'), [
                2 => ['action' => 'trigger_jsvv_alarm', 'button' => 2, 'label' => 'Zkouška sirén'],
            ]),
        ]);

        foreach (['1', '8', 'B', '9'] as $symbol) {
            JsvvAudio::create([
                'symbol' => $symbol,
                'name' => "Audio {$symbol}",
                'type' => JsvvAudioTypeEnum::FILE,
                'group' => $symbol === '1' ? JsvvAudioGroupEnum::SIREN : JsvvAudioGroupEnum::VERBAL,
            ]);
        }

        JsvvAlarm::create([
            'name' => 'Zkouška sirén',
            'sequence_1' => '1',
            'sequence_2' => '8',
            'sequence_3' => 'B',
            'sequence_4' => '9',
            'button' => 2,
        ]);

        $service = Mockery::mock(JsvvSequenceService::class);
        $service->shouldReceive('plan')->once()->andReturn(['id' => 'seq-42']);
        $service->shouldReceive('trigger')->once()->with('seq-42');
        $this->app->instance(JsvvSequenceService::class, $service);

        $response = $this->postJson('/api/control-tab/events', [
            'type' => 'button_pressed',
            'button_id' => 2,
        ]);

        $response->assertOk()->assertJsonFragment(['status' => 'queued']);
    }
}
