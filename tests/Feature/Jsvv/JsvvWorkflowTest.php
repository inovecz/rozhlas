<?php

declare(strict_types=1);

namespace Tests\Feature\Jsvv;

use App\Services\JsvvMessageService;
use App\Services\JsvvSequenceService;
use App\Services\StreamOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class JsvvWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_siren_signal_dispatches_sequence_and_triggers_execution(): void
    {
        $sequenceService = Mockery::mock(JsvvSequenceService::class);
        $sequenceService->shouldReceive('plan')
            ->once()
            ->with(Mockery::on(static function (array $items): bool {
                return $items === [['slot' => 2, 'category' => 'siren', 'repeat' => 1]];
            }), Mockery::type('array'))
            ->andReturn(['id' => 'seq-1']);
        $sequenceService->shouldReceive('trigger')
            ->once()
            ->with('seq-1');

        $messageService = Mockery::mock(JsvvMessageService::class);
        $messageService->shouldReceive('ingest')->once();

        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldNotReceive('stop');

        $this->app->instance(JsvvSequenceService::class, $sequenceService);
        $this->app->instance(JsvvMessageService::class, $messageService);
        $this->app->instance(StreamOrchestrator::class, $orchestrator);

        $payload = $this->buildFrame('SIREN_SIGNAL', ['signalType' => 2, 'repeat' => 1], 'P2');

        $response = $this->postJson('/api/jsvv/events', $payload);

        $response->assertOk()->assertJson(['status' => 'accepted']);
    }

    public function test_stop_command_halts_orchestrator_without_dispatching_sequences(): void
    {
        $sequenceService = Mockery::mock(JsvvSequenceService::class);
        $sequenceService->shouldNotReceive('plan');
        $sequenceService->shouldNotReceive('trigger');

        $messageService = Mockery::mock(JsvvMessageService::class);
        $messageService->shouldReceive('ingest')->once();

        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('stop')->once()->with('jsvv_stop_command');

        $this->app->instance(JsvvSequenceService::class, $sequenceService);
        $this->app->instance(JsvvMessageService::class, $messageService);
        $this->app->instance(StreamOrchestrator::class, $orchestrator);

        $payload = $this->buildFrame('STOP', [], 'P1');

        $response = $this->postJson('/api/jsvv/events', $payload);

        $response->assertOk()->assertJson(['status' => 'accepted']);
    }

    public function test_multiple_requests_queue_sequences_in_order(): void
    {
        $sequenceService = Mockery::mock(JsvvSequenceService::class);
        $sequenceService->shouldReceive('plan')
            ->twice()
            ->andReturn(['id' => 'seq-1'], ['id' => 'seq-2']);
        $sequenceService->shouldReceive('trigger')
            ->once()
            ->with('seq-1');
        $sequenceService->shouldReceive('trigger')
            ->once()
            ->with('seq-2');

        $messageService = Mockery::mock(JsvvMessageService::class);
        $messageService->shouldReceive('ingest')->twice();

        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldNotReceive('stop');

        $this->app->instance(JsvvSequenceService::class, $sequenceService);
        $this->app->instance(JsvvMessageService::class, $messageService);
        $this->app->instance(StreamOrchestrator::class, $orchestrator);

        $this->postJson('/api/jsvv/events', $this->buildFrame('SIREN_SIGNAL', ['signalType' => 1], 'P2'))
            ->assertOk();
        $this->postJson('/api/jsvv/events', $this->buildFrame('VERBAL_INFO', ['slot' => 3], 'P2'))
            ->assertOk();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildFrame(string $command, array $params, string $priority): array
    {
        return [
            'payload' => [
                'networkId' => 1,
                'vycId' => 1,
                'kppsAddress' => '0x01',
                'operatorId' => 10,
                'type' => 'COMMAND',
                'command' => $command,
                'params' => $params,
                'priority' => $priority,
                'timestamp' => time(),
                'rawMessage' => strtoupper($command) . '|payload',
            ],
        ];
    }
}
