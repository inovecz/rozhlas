<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ControlChannelTimeoutException;
use App\Models\ControlChannelCommand;
use App\Models\JsvvEvent;
use App\Models\JsvvMessage;
use App\Services\ControlChannelService;
use App\Services\ControlChannelTransport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ControlChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pause_dispatch_records_success(): void
    {
        config([
            'control_channel.deadline_ms' => 250,
        ]);

        /** @var MockInterface&ControlChannelTransport $transport */
        $transport = Mockery::mock(ControlChannelTransport::class);
        $transport->shouldReceive('send')
            ->once()
            ->andReturn([
                'ok' => true,
                'state' => ControlChannelService::STATE_PAUSED,
                'ts' => now()->toIso8601String(),
                'latencyMs' => 12,
            ]);

        $service = new ControlChannelService($transport);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-01-01T00:00:01Z'));
        $message = $this->createMessage(CarbonImmutable::parse('2025-01-01T00:00:00Z'));

        $record = $service->pause($message, 'P2 activation');

        $this->assertInstanceOf(ControlChannelCommand::class, $record);
        $this->assertSame('pause_modbus', $record->command);
        $this->assertSame('OK', $record->result);
        $this->assertSame(ControlChannelService::STATE_PAUSED, $record->state_after);
        $this->assertSame('P2 activation', $record->reason);

        $payload = $record->payload;
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('request', $payload);
        $this->assertArrayHasKey('response', $payload);
        $this->assertSame('pause_modbus', $payload['request']['command']);
        $this->assertTrue($payload['response']['ok']);
        $this->assertSame(12, $payload['latency_ms']);
        $this->assertSame(1000, $payload['since_message_ms']);

        $event = JsvvEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame('ControlChannelAcknowledged', $event->event);
        $this->assertSame('P2', $event->data['priority']);
        $this->assertSame('pause_modbus', $event->data['command']);
        $this->assertSame(1000, $event->data['since_message_ms']);
        $this->assertSame(12, $event->data['latency_ms']);
    }

    public function test_timeout_is_recorded(): void
    {
        /** @var MockInterface&ControlChannelTransport $transport */
        $transport = Mockery::mock(ControlChannelTransport::class);
        $transport->shouldReceive('send')
            ->once()
            ->andThrow(new ControlChannelTimeoutException('deadline exceeded'));

        $service = new ControlChannelService($transport);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-01-01T00:00:02Z'));
        $receivedAt = CarbonImmutable::parse('2025-01-01T00:00:01Z');

        $record = $service->stop($this->createMessage($receivedAt), 'Priority STOP');

        $this->assertSame('TIMEOUT', $record->result);
        $this->assertSame('stop_modbus', $record->command);
        $this->assertArrayHasKey('error', $record->payload);
        $this->assertNotEmpty($record->payload['error']);
        $this->assertSame(1000, $record->payload['since_message_ms']);

        $event = JsvvEvent::query()->first();
        $this->assertSame('ControlChannelTimeout', $event->event);
        $this->assertSame('stop_modbus', $event->data['command']);
        $this->assertSame(1000, $event->data['since_message_ms']);
    }

    public function test_command_is_skipped_when_modbus_port_missing(): void
    {
        config([
            'modbus.port' => null,
        ]);

        /** @var MockInterface&ControlChannelTransport $transport */
        $transport = Mockery::mock(ControlChannelTransport::class);
        $transport->shouldNotReceive('send');

        $service = new ControlChannelService($transport);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-01-01T00:00:05Z'));

        $record = $service->resume(reason: 'No Modbus');

        $this->assertInstanceOf(ControlChannelCommand::class, $record);
        $this->assertSame('resume_modbus', $record->command);
        $this->assertSame('SKIPPED', $record->result);
        $this->assertSame(ControlChannelService::STATE_TRANSMITTING, $record->state_after);

        $payload = $record->payload;
        $this->assertIsArray($payload);
        $this->assertTrue(Arr::get($payload, 'details.skipped', false));
        $this->assertSame('modbus_port_missing', Arr::get($payload, 'details.reason'));

        $event = JsvvEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame('ControlChannelSkipped', $event->event);
        $this->assertSame('resume_modbus', $event->data['command']);
    }

    private function createMessage(?CarbonImmutable $receivedAt = null): JsvvMessage
    {
        $time = $receivedAt ?? CarbonImmutable::now();

        return JsvvMessage::create([
            'network_id' => 1,
            'vyc_id' => 1,
            'kpps_address' => '0x0001',
            'operator_id' => 42,
            'type' => 'ACTIVATION',
            'command' => 'SIREN_SIGNAL',
            'params' => ['signalType' => 1],
            'priority' => 'P2',
            'payload_timestamp' => now()->timestamp,
            'received_at' => $time,
            'raw_message' => 'SIREN 1',
            'status' => 'VALIDATED',
            'dedup_key' => 'abc123',
        ]);
    }
}
