<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\JsvvMessageReceived;
use App\Models\JsvvEvent;
use App\Models\Log as ActivityLog;
use App\Services\SmsNotificationService;
use App\Settings\JsvvSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

class HandleJsvvFaultNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(
        private readonly SmsNotificationService $smsService = new SmsNotificationService(),
    ) {
    }

    public function handle(JsvvMessageReceived $event): void
    {
        if ($event->duplicate) {
            return;
        }

        $message = $event->message;
        if (strtoupper((string) $message->type) !== 'FAULT') {
            return;
        }

        $params = is_array($message->params) ? $message->params : [];
        $device = (string) ($params['device'] ?? Arr::get($params, 'tokens.0') ?? 'nezjištěno');
        $code = Arr::get($params, 'code', Arr::get($params, 'tokens.1'));
        $detail = (string) ($params['detail'] ?? Arr::get($params, 'tokens.2') ?? '');
        $detail = trim((string) $detail);

        $receivedAt = $message->received_at instanceof Carbon ? $message->received_at : Carbon::now();

        $this->logActivity($message->id, $device, $code, $detail, $params, $receivedAt);

        $settings = App::make(JsvvSettings::class);
        $recipients = $this->normalizeRecipients($settings->alarmSmsContacts ?? []);

        if (!$settings->allowAlarmSms || $recipients === []) {
            $this->recordEvent($message->id, 'alarm_sms_skipped', [
                'reason' => $settings->allowAlarmSms ? 'empty_recipients' : 'disabled',
                'device' => $device,
                'code' => $code,
                'detail' => $detail,
            ]);

            return;
        }

        $messageText = $this->buildSmsMessage(
            $settings->alarmSmsMessage ?? '',
            $device,
            $code,
            $detail,
            $receivedAt,
            $message->priority
        );

        $this->smsService->send($recipients, $messageText);

        $this->recordEvent($message->id, 'alarm_sms_dispatched', [
            'recipients' => $recipients,
            'device' => $device,
            'code' => $code,
            'detail' => $detail,
        ]);
    }

    private function logActivity(
        int $messageId,
        string $device,
        mixed $code,
        string $detail,
        array $params,
        Carbon $receivedAt,
    ): void {
        $title = sprintf('JSVV alarm: %s', $device);

        $segments = [];
        if ($code !== null && $code !== '') {
            $segments[] = sprintf('kód %s', $code);
        }
        if ($detail !== '') {
            $segments[] = $detail;
        }
        $summary = $segments === [] ? 'Poplach byl zaznamenán.' : implode(', ', $segments);
        $description = sprintf(
            '%s (%s) v %s.',
            $summary,
            $device,
            $receivedAt->format('d.m.Y H:i:s')
        );

        ActivityLog::create([
            'type' => 'jsvv',
            'title' => $title,
            'description' => $description,
            'data' => [
                'message_id' => $messageId,
                'device' => $device,
                'code' => $code,
                'detail' => $detail,
                'params' => $params,
                'received_at' => $receivedAt->toIso8601String(),
            ],
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

    private function buildSmsMessage(
        string $template,
        string $device,
        mixed $code,
        string $detail,
        Carbon $receivedAt,
        ?string $priority,
    ): string {
        $replacements = [
            '{device}' => $device,
            '{code}' => $code !== null && $code !== '' ? (string) $code : 'neuvedeno',
            '{detail}' => $detail !== '' ? $detail : 'bez detailu',
            '{time}' => $receivedAt->format('H:i'),
            '{date}' => $receivedAt->format('d.m.Y'),
            '{priority}' => $priority ?? 'neuvedeno',
        ];

        $base = $template !== ''
            ? str_replace(array_keys($replacements), array_values($replacements), $template)
            : sprintf(
                'JSVV alarm: %s (kód %s) – %s v %s.',
                $replacements['{device}'],
                $replacements['{code}'],
                $detail !== '' ? $detail : 'detail neuveden',
                $receivedAt->format('d.m.Y H:i')
            );

        return trim(preg_replace('/\s+/u', ' ', $base) ?? '');
    }

    private function recordEvent(int $messageId, string $event, array $data): void
    {
        JsvvEvent::create([
            'message_id' => $messageId,
            'event' => $event,
            'data' => $data,
        ]);
    }
}

