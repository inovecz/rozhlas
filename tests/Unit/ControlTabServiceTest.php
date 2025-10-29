<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\JsvvAudioGroupEnum;
use App\Enums\JsvvAudioTypeEnum;
use App\Exceptions\BroadcastLockedException;
use App\Jobs\RunJsvvSequence;
use App\Models\BroadcastSession;
use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use App\Services\ControlTabService;
use App\Services\JsvvSequenceService;
use App\Services\StreamOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ControlTabServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_start_stream_button(): void
    {
        config([
            'control_tab.defaults' => [
                'route' => [],
                'locations' => [],
                'nests' => [],
                'options' => ['note' => 'Control Tab'],
            ],
            'control_tab.buttons' => [
                1 => ['action' => 'start_stream', 'source' => 'microphone'],
            ],
        ]);

        /** @var MockInterface $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')
            ->once()
            ->andReturn(['status' => 'running', 'source' => 'microphone']);

        $service = new ControlTabService($orchestrator, Mockery::mock(JsvvSequenceService::class));

        $response = $service->handleButtonPress(1);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('Vysílání bylo spuštěno přes Control Tab.', $response['message']);
    }

    public function test_start_stream_blocked_by_jsvv(): void
    {
        config([
            'control_tab.defaults' => [
                'route' => [],
                'locations' => [],
                'nests' => [],
            ],
            'control_tab.buttons' => [
                1 => ['action' => 'start_stream', 'source' => 'microphone'],
            ],
        ]);

        /** @var MockInterface $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')
            ->once()
            ->andThrow(new BroadcastLockedException());

        $service = new ControlTabService($orchestrator, Mockery::mock(JsvvSequenceService::class));

        $response = $service->handleButtonPress(1);

        $this->assertSame('blocked', $response['status']);
    }

    public function test_trigger_jsvv_alarm_enqueues_sequence(): void
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
        JsvvAudio::create([
            'symbol' => '8',
            'name' => 'Gong 1',
            'type' => JsvvAudioTypeEnum::FILE,
            'group' => JsvvAudioGroupEnum::VERBAL,
        ]);

        JsvvAlarm::create([
            'name' => 'Test Alarm',
            'sequence_1' => '1',
            'sequence_2' => '8',
            'button' => 2,
            'mobile_button' => 2,
        ]);

        Bus::fake();

        /** @var MockInterface $seqService */
        $seqService = Mockery::mock(JsvvSequenceService::class);
        $seqService->shouldReceive('plan')
            ->once()
            ->andReturn(['id' => 'seq-id', 'status' => 'planned']);
        $seqService->shouldReceive('trigger')
            ->once()
            ->andReturnUsing(function () {
                Bus::dispatch(new RunJsvvSequence('seq-id'));

                return ['id' => 'seq-id', 'status' => 'queued', 'queue_position' => 1];
            });

        $service = new ControlTabService(Mockery::mock(StreamOrchestrator::class), $seqService);

        $response = $service->handleButtonPress(13);

        $this->assertSame('queued', $response['status']);
        Bus::assertDispatched(RunJsvvSequence::class);
    }

    public function test_select_jsvv_alarm_stores_choice(): void
    {
        Cache::flush();

        config([
            'control_tab.buttons' => [
                3 => ['action' => 'select_jsvv_alarm', 'button' => 5, 'label' => 'Chemická havárie'],
            ],
        ]);

        $service = new ControlTabService(Mockery::mock(StreamOrchestrator::class), Mockery::mock(JsvvSequenceService::class));

        $response = $service->handleButtonPress(3);

        $this->assertSame('ok', $response['status']);
        $selected = Cache::get('control_tab:selected_jsvv_alarm');
        $this->assertIsArray($selected);
        $this->assertSame(5, $selected['button']);
    }

    public function test_trigger_selected_jsvv_alarm_uses_cached_selection(): void
    {
        Cache::flush();

        config([
            'control_tab.buttons' => [
                13 => ['action' => 'trigger_selected_jsvv_alarm', 'fallback_button' => 2],
            ],
        ]);

        JsvvAudio::create([
            'symbol' => '1',
            'name' => 'Kolísavý tón',
            'type' => JsvvAudioTypeEnum::FILE,
            'group' => JsvvAudioGroupEnum::SIREN,
        ]);
        JsvvAudio::create([
            'symbol' => '8',
            'name' => 'Gong 1',
            'type' => JsvvAudioTypeEnum::FILE,
            'group' => JsvvAudioGroupEnum::VERBAL,
        ]);

        JsvvAlarm::create([
            'name' => 'Požární poplach',
            'sequence_1' => '1',
            'sequence_2' => '8',
            'button' => 3,
            'mobile_button' => 3,
        ]);

        Cache::put('control_tab:selected_jsvv_alarm', [
            'button' => 3,
            'label' => 'Požární poplach',
        ], now()->addMinute());

        Bus::fake();

        /** @var MockInterface $seqService */
        $seqService = Mockery::mock(JsvvSequenceService::class);
        $seqService->shouldReceive('plan')
            ->once()
            ->andReturn(['id' => 'seq-fire', 'status' => 'planned']);
        $seqService->shouldReceive('trigger')
            ->once()
            ->andReturnUsing(function () {
                Bus::dispatch(new RunJsvvSequence('seq-fire'));

                return ['id' => 'seq-fire', 'status' => 'queued', 'queue_position' => 1];
            });

        $service = new ControlTabService(Mockery::mock(StreamOrchestrator::class), $seqService);

        $response = $service->handleButtonPress(13);

        $this->assertSame('queued', $response['status']);
        Bus::assertDispatched(RunJsvvSequence::class);
    }

    public function test_start_or_trigger_selected_jsvv_alarm_triggers_jsvv_when_selection_exists(): void
    {
        Cache::flush();

        config([
            'control_tab.buttons' => [
                9 => [
                    'action' => 'start_or_trigger_selected_jsvv_alarm',
                    'source' => 'microphone',
                    'success_message' => 'Přímé hlášení bylo spuštěno.',
                ],
            ],
        ]);

        JsvvAudio::create([
            'symbol' => '1',
            'name' => 'Kolísavý tón',
            'type' => JsvvAudioTypeEnum::FILE,
            'group' => JsvvAudioGroupEnum::SIREN,
        ]);

        JsvvAudio::create([
            'symbol' => '8',
            'name' => 'Gong 1',
            'type' => JsvvAudioTypeEnum::FILE,
            'group' => JsvvAudioGroupEnum::VERBAL,
        ]);

        JsvvAlarm::create([
            'name' => 'Zkouška sirén',
            'sequence_1' => '1',
            'sequence_2' => '8',
            'button' => 2,
            'mobile_button' => 2,
        ]);

        Cache::put('control_tab:selected_jsvv_alarm', [
            'button' => 2,
            'label' => 'Zkouška sirén',
        ], now()->addMinute());

        Bus::fake();

        /** @var MockInterface $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldNotReceive('start');

        /** @var MockInterface $seqService */
        $seqService = Mockery::mock(JsvvSequenceService::class);
        $seqService->shouldReceive('plan')
            ->once()
            ->andReturn(['id' => 'seq-test', 'status' => 'planned']);
        $seqService->shouldReceive('trigger')
            ->once()
            ->andReturnUsing(function () {
                Bus::dispatch(new RunJsvvSequence('seq-test'));

                return ['id' => 'seq-test', 'status' => 'queued', 'queue_position' => 1];
            });

        $service = new ControlTabService($orchestrator, $seqService);

        $response = $service->handleButtonPress(9);

        $this->assertSame('queued', $response['status']);
        $this->assertNull(Cache::get('control_tab:selected_jsvv_alarm'));
        Bus::assertDispatched(RunJsvvSequence::class);
    }

    public function test_start_or_trigger_selected_jsvv_alarm_starts_stream_without_selection(): void
    {
        Cache::flush();

        config([
            'control_tab.defaults' => [
                'route' => [],
                'locations' => [],
                'nests' => [],
            ],
            'control_tab.buttons' => [
                9 => [
                    'action' => 'start_or_trigger_selected_jsvv_alarm',
                    'source' => 'microphone',
                    'success_message' => 'Přímé hlášení bylo spuštěno.',
                ],
            ],
        ]);

        /** @var MockInterface $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')
            ->once()
            ->andReturn(['status' => 'running', 'source' => 'microphone']);

        /** @var MockInterface $seqService */
        $seqService = Mockery::mock(JsvvSequenceService::class);
        $seqService->shouldNotReceive('plan');
        $seqService->shouldNotReceive('trigger');

        $service = new ControlTabService($orchestrator, $seqService);

        $response = $service->handleButtonPress(9);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('Přímé hlášení bylo spuštěno.', $response['message']);
    }

    public function test_text_request_returns_summary(): void
    {
        config([
            'control_tab.text_fields' => [
                1 => 'status_summary',
            ],
        ]);

        BroadcastSession::query()->delete();

        $service = new ControlTabService(Mockery::mock(StreamOrchestrator::class), Mockery::mock(JsvvSequenceService::class));

        $response = $service->handleTextRequest(1);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('Ústředna je připravena.', $response['text']);
    }
}
