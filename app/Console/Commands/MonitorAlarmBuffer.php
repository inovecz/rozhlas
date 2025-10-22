<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\PythonClient;
use App\Services\SmsNotificationService;
use App\Settings\JsvvSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class MonitorAlarmBuffer extends Command implements SignalableCommandInterface
{
    protected $signature = 'alarms:monitor {--interval=5 : Interval ve vteřinách mezi dotazy}';

    protected $description = 'Pravidelně kontroluje Modbus alarm buffer (0x3000-0x3009) a rozesílá SMS notifikace.';

    private bool $shouldExit = false;

    public function __construct(
        private readonly PythonClient $pythonClient = new PythonClient(),
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));

        $this->info('Spouštím monitor alarm bufferu...');

        while (!$this->shouldExit) {
            $this->tick();
            sleep($interval);
        }

        $this->info('Monitor alarm bufferu byl ukončen.');

        return self::SUCCESS;
    }

    private function tick(): void
    {
        $response = $this->pythonClient->readAlarmBuffer();
        if (($response['success'] ?? false) === false) {
            Log::warning('Čtení alarm bufferu selhalo', $response);
            return;
        }

        $alarm = Arr::get($response, 'json.data.alarm', []);
        if (!is_array($alarm)) {
            return;
        }

        $nest = (int) ($alarm['nest_address'] ?? 0);
        $repeat = (int) ($alarm['repeat'] ?? 0);
        $data = array_map(static fn ($value) => (int) $value, $alarm['data'] ?? []);

        if ($nest === 0 && $repeat === 0 && array_sum($data) === 0) {
            return; // prázdný buffer
        }

        $this->notifyAlarmSms($nest, $repeat, $data);
    }

    private function notifyAlarmSms(int $nest, int $repeat, array $data): void
    {
        $settings = app(JsvvSettings::class);
        if (!$settings->allowAlarmSms || empty($settings->alarmSmsContacts)) {
            return;
        }

        $template = $settings->alarmSmsMessage ?: 'Alarm z hnízda {nest} (opakování {repeat}) – data: {data}.';
        $replacements = [
            '{nest}' => (string) $nest,
            '{repeat}' => (string) $repeat,
            '{data}' => implode(', ', array_map(static fn ($value) => (string) $value, $data)),
            '{time}' => now()->format('H:i'),
            '{date}' => now()->format('d.m.Y'),
        ];

        $message = str_replace(array_keys($replacements), array_values($replacements), $template);
        $this->smsService->send($settings->alarmSmsContacts, $message);
    }

    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldExit = true;
        return 0;
    }
}
