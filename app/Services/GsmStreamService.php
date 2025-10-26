<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BroadcastLockedException;
use App\Libraries\PythonClient;
use App\Models\GsmCallSession;
use App\Models\GsmPinVerification;
use App\Models\GsmWhitelistEntry;
use App\Models\StreamTelemetryEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GsmStreamService extends Service
{
    public function __construct(
        private readonly PythonClient $pythonClient = new PythonClient(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
    ) {
        parent::__construct();
    }

    public function handleIncomingCall(array $event): array
    {
        Log::info('GSM event received', $event);

        $state = strtolower((string) Arr::get($event, 'state'));
        $caller = (string) Arr::get($event, 'caller', '');
        $sessionId = (string) Arr::get($event, 'session_id', Arr::get($event, 'sessionId', Str::uuid()));

        $session = GsmCallSession::query()->find($sessionId) ?? new GsmCallSession(['id' => $sessionId]);
        $session->fill([
            'caller' => $caller,
        ]);

        if (Arr::has($event, 'metadata')) {
            $session->metadata = Arr::get($event, 'metadata', []);
        }

        $response = [
            'sessionId' => $sessionId,
            'action' => 'ack',
        ];

        if ($state === 'ringing') {
            $session->status = 'ringing';
            $session->started_at = now();
            $session->authorised = $this->isWhitelisted($caller);
            $session->save();

            if (!$session->authorised) {
                $this->createPendingPin($session);
            }
            $this->recordTelemetry('gsm_call_ringing', $session, [
                'caller' => $caller,
                'authorised' => $session->authorised,
            ]);

            $response['authorised'] = $session->authorised;
            $response['action'] = $session->authorised ? 'answer' : 'reject';

            return $response;
        }

        if ($state === 'accepted') {
            $session->status = 'accepted';
            $session->authorised = $this->isWhitelisted($caller) || $this->hasVerifiedPin($session);
            $session->save();

            if ($session->authorised) {
                try {
                    $this->orchestrator->start([
                        'source' => 'gsm',
                        'route' => Arr::get($session->metadata, 'route', []),
                        'zones' => Arr::get($session->metadata, 'zones', []),
                        'options' => ['caller' => $caller],
                    ]);
                    $this->markStreamStarted($session);
                    $this->recordTelemetry('gsm_call_started', $session, [
                        'caller' => $caller,
                    ]);
                } catch (BroadcastLockedException $exception) {
                    Log::warning('GSM stream blocked by active JSVV sequence', [
                        'caller' => $caller,
                        'session_id' => $session->id,
                    ]);
                    $session->status = 'blocked';
                    $session->ended_at = now();
                    $session->save();
                    $this->clearPendingPins($session);
                    $this->recordTelemetry('gsm_call_blocked', $session, [
                        'caller' => $caller,
                    ]);

                    $response['action'] = 'hangup';
                    return $response;
                }

                $response['authorised'] = true;
                return $response;
            }

            $this->recordTelemetry('gsm_call_unauthorised', $session, [
                'caller' => $caller,
            ]);
            $response['authorised'] = false;
            $response['action'] = 'hangup';

            return $response;
        }

        if (in_array($state, ['finished', 'rejected', 'error'], true)) {
            $session->status = $state;
            $session->ended_at = now();
            $session->save();

            if ($this->hasStreamStarted($session)) {
                $this->orchestrator->stop(sprintf('gsm_%s', $state));
                $this->recordTelemetry('gsm_call_finished', $session, [
                    'caller' => $caller,
                    'status' => $state,
                ]);
            } else {
                $this->recordTelemetry('gsm_call_ended', $session, [
                    'caller' => $caller,
                    'status' => $state,
                ]);
            }

            $this->clearPendingPins($session);
            $response['action'] = 'ack';

            return $response;
        }

        return $response;
    }

    public function listWhitelist(): array
    {
        return GsmWhitelistEntry::query()->orderBy('number')->get()->toArray();
    }

    public function upsertWhitelist(?string $id, array $payload): array
    {
        $entry = GsmWhitelistEntry::query()->find($id) ?? new GsmWhitelistEntry(['id' => $id]);
        $entry->fill([
            'number' => $payload['number'] ?? $entry->number,
            'label' => $payload['label'] ?? $entry->label,
            'priority' => $payload['priority'] ?? $entry->priority,
        ]);
        $entry->save();

        return $entry->toArray();
    }

    public function deleteWhitelist(string $id): bool
    {
        $entry = GsmWhitelistEntry::query()->find($id);
        if ($entry === null) {
            return false;
        }
        $entry->delete();
        return true;
    }

    public function verifyPin(string $sessionId, string $pin): array
    {
        $verification = GsmPinVerification::query()
            ->where('session_id', $sessionId)
            ->latest('created_at')
            ->first();

        $result = [
            'sessionId' => $sessionId,
            'verified' => false,
        ];

        if ($verification === null) {
            return $result;
        }

        $verification->attempts++;
        if (!$verification->verified && hash_equals((string) $verification->pin, $pin)) {
            $verification->verified = true;
            $verification->verified_at = now();
            $result['verified'] = true;
        }
        $verification->save();

        return $result;
    }

    private function isWhitelisted(string $number): bool
    {
        return GsmWhitelistEntry::query()->where('number', $number)->exists();
    }

    private function createPendingPin(GsmCallSession $session): void
    {
        GsmPinVerification::query()->create([
            'session_id' => $session->id,
            'pin' => (string) random_int(1000, 9999),
        ]);
    }

    private function hasVerifiedPin(GsmCallSession $session): bool
    {
        return $session->pins()->where('verified', true)->exists();
    }

    private function clearPendingPins(GsmCallSession $session): void
    {
        $session->pins()->delete();
        $metadata = $session->metadata ?? [];
        unset($metadata['stream_started']);
        $session->metadata = $metadata;
        $session->save();
    }

    private function markStreamStarted(GsmCallSession $session): void
    {
        $metadata = $session->metadata ?? [];
        $metadata['stream_started'] = true;
        $session->metadata = $metadata;
        $session->save();
    }

    private function hasStreamStarted(GsmCallSession $session): bool
    {
        $metadata = $session->metadata ?? [];
        return Arr::get($metadata, 'stream_started', false) === true;
    }

    private function recordTelemetry(string $type, GsmCallSession $session, array $payload = []): void
    {
        StreamTelemetryEntry::create([
            'type' => $type,
            'payload' => array_merge([
                'session_id' => $session->id,
                'caller' => $session->caller,
            ], $payload),
            'recorded_at' => now(),
        ]);
    }
}
