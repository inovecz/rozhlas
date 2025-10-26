<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BroadcastLockedException;
use App\Libraries\PythonClient;
use App\Models\GsmCallSession;
use App\Models\GsmWhitelistEntry;
use App\Models\StreamTelemetryEntry;
use App\Services\GsmStreamService;
use App\Services\StreamOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GsmStreamServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_ringing_whitelisted_triggers_answer(): void
    {
        $number = '+4201000000';

        GsmWhitelistEntry::create([
            'number' => $number,
            'label' => 'Tester',
            'priority' => 'high',
        ]);

        /** @var MockInterface&StreamOrchestrator $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $pythonClient = Mockery::mock(PythonClient::class);
        $service = new GsmStreamService($pythonClient, $orchestrator);

        $result = $service->handleIncomingCall([
            'state' => 'ringing',
            'caller' => $number,
        ]);

        $this->assertSame('answer', $result['action']);
        $this->assertTrue($result['authorised']);
        $this->assertDatabaseHas('gsm_call_sessions', [
            'caller' => $number,
            'status' => 'ringing',
        ]);
        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'gsm_call_ringing',
        ]);
    }

    public function test_accepted_starts_stream_and_returns_ack(): void
    {
        $number = '+4201000001';

        GsmWhitelistEntry::create([
            'number' => $number,
            'label' => 'Tester',
        ]);

        $session = GsmCallSession::create([
            'caller' => $number,
            'status' => 'ringing',
            'authorised' => true,
        ]);

        /** @var MockInterface&StreamOrchestrator $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')->once();

        $service = new GsmStreamService(Mockery::mock(PythonClient::class), $orchestrator);

        $result = $service->handleIncomingCall([
            'state' => 'accepted',
            'caller' => $number,
            'session_id' => $session->id,
        ]);

        $this->assertTrue($result['authorised']);
        $this->assertSame('ack', $result['action']);
        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'gsm_call_started',
        ]);
    }

    public function test_blocked_when_jsvv_active_returns_hangup(): void
    {
        $number = '+4201000002';

        GsmWhitelistEntry::create([
            'number' => $number,
        ]);

        $session = GsmCallSession::create([
            'caller' => $number,
            'status' => 'ringing',
            'authorised' => true,
        ]);

        /** @var MockInterface&StreamOrchestrator $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('start')->once()->andThrow(new BroadcastLockedException());

        $service = new GsmStreamService(Mockery::mock(PythonClient::class), $orchestrator);

        $result = $service->handleIncomingCall([
            'state' => 'accepted',
            'caller' => $number,
            'session_id' => $session->id,
        ]);

        $this->assertSame('hangup', $result['action']);
        $this->assertDatabaseHas('gsm_call_sessions', [
            'id' => $session->id,
            'status' => 'blocked',
        ]);
    }

    public function test_finished_stops_stream_only_when_started(): void
    {
        $number = '+4201000003';

        $session = GsmCallSession::create([
            'caller' => $number,
            'status' => 'accepted',
            'authorised' => true,
            'metadata' => ['stream_started' => true],
        ]);

        /** @var MockInterface&StreamOrchestrator $orchestrator */
        $orchestrator = Mockery::mock(StreamOrchestrator::class);
        $orchestrator->shouldReceive('stop')->once()->with('gsm_finished');

        $service = new GsmStreamService(Mockery::mock(PythonClient::class), $orchestrator);

        $result = $service->handleIncomingCall([
            'state' => 'finished',
            'caller' => $number,
            'session_id' => $session->id,
        ]);

        $this->assertSame('ack', $result['action']);
        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'gsm_call_finished',
        ]);
    }
}
