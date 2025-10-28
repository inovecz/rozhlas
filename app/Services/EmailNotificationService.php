<?php

declare(strict_types=1);

namespace App\Services;

use App\Settings\SmtpSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailNotificationService
{
    public function send(array|string $recipients, string $subject, string $body, bool $isHtml = false): void
    {
        $settings = app(SmtpSettings::class);
        $host = trim($settings->host ?? '');
        $port = (int) ($settings->port ?? 0);
        $fromAddress = trim($settings->from_address ?? '');
        $fromName = trim($settings->from_name ?? '');
        $username = trim($settings->username ?? '');
        $password = $settings->password ?? '';

        if ($host === '' || $port <= 0 || $fromAddress === '') {
            Log::warning('Email notification skipped: SMTP settings are incomplete.');
            return;
        }

        $recipientList = $this->normalizeRecipients($recipients);
        if ($recipientList === []) {
            return;
        }

        try {
            $mailerName = '__two_way_notifications';
            $this->configureMailer($mailerName, $host, $port, $username, $password, $settings->encryption->value ?? null);

            $sendCallback = function ($message) use ($recipientList, $subject, $fromAddress, $fromName): void {
                $message->subject($subject);
                $message->from($fromAddress, $fromName !== '' ? $fromName : null);
                $message->to($recipientList);
            };

            if ($isHtml) {
                Mail::mailer($mailerName)->html($body, $sendCallback);
            } else {
                Mail::mailer($mailerName)->raw($body, $sendCallback);
            }
        } catch (Throwable $exception) {
            Log::error('Email notification failed', [
                'recipients' => $recipientList,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizeRecipients(array|string $recipients): array
    {
        $items = is_array($recipients) ? $recipients : [$recipients];
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

    private function configureMailer(string $name, string $host, int $port, string $username, string $password, ?string $encryption): void
    {
        $encryptionValue = null;
        $encryption = $encryption ? strtoupper($encryption) : null;
        if ($encryption === 'SSL') {
            $encryptionValue = 'ssl';
        } elseif ($encryption === 'TLS') {
            $encryptionValue = 'tls';
        }

        $config = [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'timeout' => null,
            'auth_mode' => null,
            'encryption' => $encryptionValue,
            'username' => $username !== '' ? $username : null,
            'password' => $username !== '' ? $password : null,
        ];

        Config::set("mail.mailers.$name", $config);
    }
}
