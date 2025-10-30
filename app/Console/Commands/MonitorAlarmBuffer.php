<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\PythonClient;
use App\Models\BroadcastSession;
use App\Models\StreamTelemetryEntry;
use App\Services\EmailNotificationService;
use App\Services\Modbus\AlarmDecoder;
use App\Services\SmsNotificationService;
use App\Settings\JsvvSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class MonitorAlarmBuffer extends Command implements SignalableCommandInterface
{
    protected $signature = 'alarms:monitor {--interval=5 : Interval ve vteřinách mezi dotazy}';

    protected $description = 'Pravidelně kontroluje Modbus alarm buffer (0x3000-0x3009) a rozesílá notifikace (SMS/e-mail).';

    private bool $shouldExit = false;

    private const MODBUS_LOCK_KEY = 'modbus:serial';
    private const JSVV_ACTIVE_LOCK_KEY = 'jsvv:sequence:active';

    public function __construct(
        private readonly PythonClient $pythonClient = new PythonClient(),
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
        private readonly EmailNotificationService $emailService = new EmailNotificationService(),
        private readonly AlarmDecoder $alarmDecoder = new AlarmDecoder(),
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
        if ($this->shouldSkipPolling()) {
            Log::debug('Alarm buffer polling skipped – Modbus je zaneprázdněný aktivním vysíláním.');
            return;
        }

        $lock = Cache::lock(self::MODBUS_LOCK_KEY, 10);
        if (!$lock->get()) {
            Log::debug('Alarm buffer polling skipped – zámek Modbus portu je obsazen.');
            return;
        }

        try {
            $response = $this->pythonClient->readAlarmBuffer();
        } finally {
            $lock->release();
        }

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

        $decoded = $this->alarmDecoder->decode($data);

        Log::info('Alarm received from Modbus buffer', [
            'nest' => $nest,
            'repeat' => $repeat,
            'decoded' => $decoded,
        ]);

        $this->recordTelemetry($nest, $repeat, $decoded);
        $this->notifyAlarmSms($nest, $repeat, $data, $decoded);
        $this->notifyAlarmEmail($nest, $repeat, $data, $decoded);
    }

    private function notifyAlarmSms(int $nest, int $repeat, array $data, array $decoded): void
    {
        $settings = app(JsvvSettings::class);
        $recipients = $this->normalizeRecipients($settings->alarmSmsContacts ?? []);
        if (!$settings->allowAlarmSms || $recipients === []) {
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

        foreach ($decoded['placeholders'] ?? [] as $token => $value) {
            $replacements[$token] = (string) $value;
        }

        $message = $this->renderTemplate($template, $replacements);
        $this->smsService->send($recipients, $message);
    }

    private function notifyAlarmEmail(int $nest, int $repeat, array $data, array $decoded): void
    {
        $settings = app(JsvvSettings::class);
        $recipients = $this->normalizeRecipients($settings->emailContacts ?? []);
        if (!$settings->allowEmail || $recipients === []) {
            return;
        }

        $replacements = [
            '{nest}' => (string) $nest,
            '{repeat}' => (string) $repeat,
            '{data}' => implode(', ', array_map(static fn ($value) => (string) $value, $data)),
            '{time}' => now()->format('H:i'),
            '{date}' => now()->format('d.m.Y'),
        ];

        foreach ($decoded['placeholders'] ?? [] as $token => $value) {
            $replacements[$token] = (string) $value;
        }

        $subjectTemplate = $settings->emailSubject ?: 'Alarm z hnízda {nest}';
        $bodyTemplate = $settings->emailMessage ?: 'Alarm z hnízda {nest} (opakování {repeat}) – data: {data}.';

        $subject = $this->renderTemplate($subjectTemplate, $replacements);
        $body = $this->renderTemplate($bodyTemplate, $replacements);

        $this->emailService->send($recipients, $subject, $body);
    }

    private function recordTelemetry(int $nest, int $repeat, array $decoded): void
    {
        StreamTelemetryEntry::create([
            'type' => 'alarm_event',
            'payload' => [
                'nest' => $nest,
                'repeat' => $repeat,
                'alarm' => $decoded,
            ],
            'recorded_at' => now(),
        ]);
    }

    private function normalizeRecipients(array|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            $parts = preg_split('/[;,]+/', (string) $item) ?: [];
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '' && !in_array($trimmed, $normalized, true)) {
                    $normalized[] = $trimmed;
                }
            }
        }

        return $normalized;
    }

    private function renderTemplate(string $template, array $replacements): string
    {
        if ($template === '') {
            return '';
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
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

    private function shouldSkipPolling(): bool
    {
        $jsvvActive = Cache::has(self::JSVV_ACTIVE_LOCK_KEY);
        $streamRunning = BroadcastSession::query()
            ->where('status', 'running')
            ->exists();

        return $jsvvActive || $streamRunning;
    }
}
