<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MonitorAlarmBuffer;
use App\Libraries\PythonClient;
use App\Models\StreamTelemetryEntry;
use App\Services\EmailNotificationService;
use App\Services\SmsNotificationService;
use App\Services\Modbus\AlarmDecoder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AlarmMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::swap(new Repository(new ArrayStore()));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_alarm_buffer_low_battery_triggers_notifications_and_telemetry(): void
    {
        $pythonClient = Mockery::mock(PythonClient::class);
        $pythonClient->shouldReceive('readAlarmBuffer')->once()->andReturn([
            'success' => true,
            'json' => [
                'data' => [
                    'alarm' => [
                        'nest_address' => 2047,
                        'repeat' => 2,
                        'data' => [0, 0, 1120, 15, 0, 0, 0, 0],
                    ],
                ],
            ],
        ]);

        $smsService = Mockery::mock(SmsNotificationService::class);
        $smsService->shouldReceive('send')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(static fn(string $message): bool => str_contains($message, 'Slabá baterie')));

        $emailService = Mockery::mock(EmailNotificationService::class);
        $emailService->shouldReceive('send')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(static fn(string $subject): bool => str_contains($subject, 'Alarm')), Mockery::type('string'));

        $settings = app(\App\Settings\JsvvSettings::class);
        $settings->allowAlarmSms = true;
        $settings->alarmSmsContacts = ['+420123456789'];
        $settings->allowEmail = true;
        $settings->emailContacts = ['ops@example.test'];
        $settings->save();

        $command = new MonitorAlarmBuffer($pythonClient, $smsService, $emailService, new AlarmDecoder());
        $this->invokePoll($command);

        $this->assertDatabaseHas('stream_telemetry_entries', [
            'type' => 'alarm_event',
        ]);
        $entry = StreamTelemetryEntry::query()->latest()->first();
        $this->assertNotNull($entry);
        $this->assertSame('battery_voltage_low', $entry->payload['alarm']['code'] ?? null);
    }

    private function invokePoll(MonitorAlarmBuffer $command): void
    {
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('tick');
        $method->setAccessible(true);
        $method->invoke($command);
    }
}
