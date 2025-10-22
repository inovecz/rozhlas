<?php

declare(strict_types=1);

namespace App\Services;

use DigitalCz\GoSms\GoSms;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsNotificationService
{
    private ?GoSms $client = null;
    private int $channel = 6;
    private ?string $sender = null;

    public function __construct(?GoSms $client = null)
    {
        $clientId = config('sms.gosms.client_id') ?? config('sms.gosms.username');
        $clientSecret = config('sms.gosms.client_secret') ?? config('sms.gosms.password');

        if (!$clientId || !$clientSecret) {
            Log::warning('SMS credentials are not configured; SMS sending disabled.');
            return;
        }

        try {
            $this->client = $client ?? new GoSms([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to initialise GoSMS client', [
                'message' => $exception->getMessage(),
            ]);
            $this->client = null;
            return;
        }

        $channel = config('sms.gosms.channel');
        if ($channel !== null) {
            $this->channel = (int) $channel;
        }

        $sender = config('sms.gosms.sender');
        if ($sender !== null && $sender !== '') {
            $this->sender = $sender;
        }
    }

    public function send(array|string $recipients, string $message): void
    {
        if ($this->client === null) {
            return;
        }

        $trimmedMessage = trim($message);
        if ($trimmedMessage === '') {
            return;
        }

        $numbers = is_array($recipients) ? $recipients : [$recipients];

        $uniqueNumbers = [];
        foreach ($numbers as $number) {
            $trimmed = trim((string) $number);
            if ($trimmed === '' || in_array($trimmed, $uniqueNumbers, true)) {
                continue;
            }
            $uniqueNumbers[] = $trimmed;
        }

        if ($uniqueNumbers === []) {
            return;
        }

        $payload = [
            'message' => $trimmedMessage,
            'recipients' => count($uniqueNumbers) === 1 ? $uniqueNumbers[0] : $uniqueNumbers,
            'channel' => $this->channel,
        ];

        if ($this->sender !== null) {
            $payload['sender'] = $this->sender;
        }

        try {
            $this->client->messages()->create($payload);
        } catch (Throwable $exception) {
            Log::error('SMS sending failed', [
                'recipients' => $uniqueNumbers,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
