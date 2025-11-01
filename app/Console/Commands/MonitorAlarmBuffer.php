<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Libraries\PythonClient;
use App\Models\BroadcastSession;
use App\Models\Log as ActivityLog;
use App\Services\EmailNotificationService;
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
    private const MAX_BUFFER_DRAIN = 8;

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
        if ($this->shouldSkipPolling()) {
            Log::debug('Alarm buffer polling skipped – Modbus je zaneprázdněný aktivním vysíláním.');
            return;
        }

        $entries = $this->drainAlarmEntries();
        if ($entries === []) {
            return;
        }

        foreach ($entries as $entry) {
            $this->notifyAlarmSms($entry['nest'], $entry['repeat'], $entry['data']);
            $this->notifyAlarmEmail($entry['nest'], $entry['repeat'], $entry['data']);
            $this->recordAlarmLog($entry['nest'], $entry['repeat'], $entry['data'], 'processed', [
                'raw' => $entry['raw'],
            ]);
        }
    }

    /**
     * @return array<int, array{nest:int, repeat:int, data:array<int,int>, raw:array<string,mixed>}>
     */
    private function drainAlarmEntries(): array
    {
        $entries = [];
        $lock = Cache::lock(self::MODBUS_LOCK_KEY, 10);
        if (!$lock->get()) {
            Log::debug('Alarm buffer polling skipped – zámek Modbus portu je obsazen.');
            return [];
        }

        try {
            $seen = [];
            for ($index = 0; $index < self::MAX_BUFFER_DRAIN; $index++) {
                $response = $this->pythonClient->readAlarmBuffer();
                if (($response['success'] ?? false) === false) {
                    Log::warning('Čtení alarm bufferu selhalo', $response);
                    $this->recordAlarmLog(0, 0, [], 'read_failed', [
                        'response' => Arr::get($response, 'stderr', $response),
                    ]);
                    break;
                }

                $entry = $this->normalizeAlarmEntry(Arr::get($response, 'json.data.alarm'));
                if ($entry === null) {
                    break;
                }

                if (in_array($entry['hash'], $seen, true)) {
                    Log::debug('Alarm buffer returned duplicate entry, stopping drain.', [
                        'hash' => $entry['hash'],
                    ]);
                    break;
                }

                $seen[] = $entry['hash'];
                $entries[] = [
                    'nest' => $entry['nest'],
                    'repeat' => $entry['repeat'],
                    'data' => $entry['data'],
                    'raw' => $entry['raw'],
                ];
            }

            if ($entries !== []) {
                $clearResponse = $this->pythonClient->clearAlarmBuffer();
                if (($clearResponse['success'] ?? true) === false) {
                    Log::debug('Vynulování alarm bufferu hlásí chybu', $clearResponse);
                    $this->recordAlarmLog(0, 0, [], 'clear_failed', [
                        'response' => Arr::get($clearResponse, 'stderr', $clearResponse),
                    ]);
                }
            }
        } finally {
            $lock->release();
        }

        return $entries;
    }

    /**
     * @return array{nest:int, repeat:int, data:array<int,int>, raw:array<string,mixed>, hash:string}|null
     */
    private function normalizeAlarmEntry(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $nest = (int) ($raw['nest_address'] ?? $raw['nestAddress'] ?? 0);
        $repeat = (int) ($raw['repeat'] ?? $raw['repeat_count'] ?? 0);
        $dataValues = $raw['data'] ?? [];
        if (!is_array($dataValues)) {
            $dataValues = [];
        }
        $data = array_map(static fn ($value): int => (int) $value, $dataValues);

        if ($nest === 0 && $repeat === 0 && array_sum($data) === 0) {
            return null;
        }

        return [
            'nest' => $nest,
            'repeat' => $repeat,
            'data' => $data,
            'raw' => $raw,
            'hash' => sha1($nest . '|' . $repeat . '|' . implode(',', $data)),
        ];
    }

    private function recordAlarmLog(int $nest, int $repeat, array $data, string $status, array $context = []): void
    {
        try {
            ActivityLog::create([
                'type' => 'alarm',
                'title' => sprintf('Alarm z hnízda %d', $nest),
                'description' => match ($status) {
                    'processed' => sprintf('Alarm byl zpracován (opakování %d).', $repeat),
                    'read_failed' => 'Nepodařilo se načíst alarm z Modbus registrů.',
                    'clear_failed' => 'Alarm byl načten, ale nepodařilo se vynulovat registr.',
                    default => sprintf('Stav zpracování: %s.', $status),
                },
                'data' => [
                    'status' => $status,
                    'nest' => $nest,
                    'repeat' => $repeat,
                    'data' => $data,
                ] + $context,
            ]);
        } catch (\Throwable $exception) {
            Log::debug('Unable to record alarm activity log.', [
                'nest' => $nest,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
        }
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

    private function shouldSkipPolling(): bool
    {
        $jsvvActive = Cache::has(self::JSVV_ACTIVE_LOCK_KEY);
        $streamRunning = BroadcastSession::query()
            ->where('status', 'running')
            ->exists();

        return $jsvvActive || $streamRunning;
    }
}
