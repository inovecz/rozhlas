<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\PythonClient;
use App\Services\EmailNotificationService;
use App\Services\SmsNotificationService;
use App\Settings\JsvvSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class MonitorAlarmBuffer extends Command implements SignalableCommandInterface
{
    protected $signature = 'alarms:monitor {--interval=5 : Interval ve vteřinách mezi dotazy}';

    protected $description = 'Pravidelně kontroluje Modbus alarm buffer (0x3000-0x3009) a rozesílá notifikace (SMS/e-mail).';

    private bool $shouldExit = false;

    public function __construct(
        private readonly PythonClient $pythonClient = new PythonClient(),
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
        private readonly EmailNotificationService $emailService = new EmailNotificationService(),
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
        $this->notifyAlarmEmail($nest, $repeat, $data);
    }

    private function notifyAlarmSms(int $nest, int $repeat, array $data): void
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

        $message = $this->renderTemplate($template, $replacements);
        $this->smsService->send($recipients, $message);
    }

    private function notifyAlarmEmail(int $nest, int $repeat, array $data): void
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

        $subjectTemplate = $settings->emailSubject ?: 'Alarm z hnízda {nest}';
        $bodyTemplate = $settings->emailMessage ?: 'Alarm z hnízda {nest} (opakování {repeat}) – data: {data}.';

        $subject = $this->renderTemplate($subjectTemplate, $replacements);
        $body = $this->renderTemplate($bodyTemplate, $replacements);

        $this->emailService->send($recipients, $subject, $body);
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
}
