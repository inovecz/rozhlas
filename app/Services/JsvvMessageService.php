<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\JsvvMessageReceived;
use App\Models\JsvvEvent;
use App\Models\JsvvMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class JsvvMessageService
{
    /**
     * @throws ValidationException
     */
    public function ingest(array $payload): array
    {
        $validator = Validator::make($payload, $this->rules());
        if ($validator->fails()) {
            $this->recordValidationFailure($payload, $validator->errors()->toArray());
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $params = Arr::get($validated, 'params', []);
        $dedupKey = $this->buildDedupKey(
            (int) $validated['networkId'],
            (int) $validated['vycId'],
            (string) $validated['kppsAddress'],
            (string) $validated['type'],
            (string) $validated['command'],
            $params,
            (int) $validated['timestamp'],
        );

        $receivedAt = CarbonImmutable::now();

        /** @var JsvvMessage $message */
        [$message, $duplicate] = DB::transaction(function () use ($validated, $params, $dedupKey, $receivedAt): array {
            $existing = JsvvMessage::query()->where('dedup_key', $dedupKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->recordEvent($existing, 'duplicate_detected', [
                    'payload' => $validated ?? [],
                ]);

                return [$existing, true];
            }

            $message = JsvvMessage::create([
                'network_id' => (int) $validated['networkId'],
                'vyc_id' => (int) $validated['vycId'],
                'kpps_address' => (string) $validated['kppsAddress'],
                'operator_id' => Arr::get($validated, 'operatorId'),
                'type' => (string) $validated['type'],
                'command' => (string) $validated['command'],
                'params' => $params,
                'priority' => (string) $validated['priority'],
                'payload_timestamp' => (int) $validated['timestamp'],
                'received_at' => $receivedAt,
                'raw_message' => (string) $validated['rawMessage'],
                'status' => 'VALIDATED',
                'dedup_key' => $dedupKey,
                'meta' => Arr::except($validated, ['params']),
            ]);

            $this->recordEvent($message, 'message_validated', [
                'priority' => $message->priority,
                'type' => $message->type,
            ]);

            return [$message, false];
        });

        event(new JsvvMessageReceived($message, $duplicate));

        return [
            'message' => $message,
            'duplicate' => $duplicate,
        ];
    }

    private function rules(): array
    {
        return [
            'networkId' => ['required', 'integer', 'min:0', 'max:255'],
            'vycId' => ['required', 'integer', 'min:0', 'max:255'],
            'kppsAddress' => ['required', 'string', 'max:32'],
            'operatorId' => ['nullable', 'integer', 'min:0'],
            'type' => ['required', 'string', 'max:32'],
            'command' => ['required', 'string', 'max:64'],
            'params' => ['required', 'array'],
            'priority' => ['required', 'string', 'in:P1,P2,P3'],
            'timestamp' => ['required', 'integer', 'min:0'],
            'rawMessage' => ['required', 'string'],
            'artisanExit' => ['nullable', 'integer'],
        ];
    }

    private function buildDedupKey(
        int $networkId,
        int $vycId,
        string $kppsAddress,
        string $type,
        string $command,
        array $params,
        int $timestamp,
    ): string {
        $normalizedParams = $this->normalizeParams($params);

        $components = [
            $networkId,
            $vycId,
            $kppsAddress,
            $type,
            $command,
            $normalizedParams,
            $timestamp,
        ];

        return hash('sha256', implode('|', $components));
    }

    private function normalizeParams(array $params): string
    {
        ksort($params);

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = json_decode(
                    $this->normalizeParams($value),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }
        }

        return json_encode($params, JSON_THROW_ON_ERROR);
    }

    private function recordEvent(JsvvMessage $message, string $event, array $data = []): void
    {
        JsvvEvent::create([
            'message_id' => $message->id,
            'event' => $event,
            'data' => $data,
        ]);
    }

    private function recordValidationFailure(array $payload, array $errors): void
    {
        JsvvEvent::create([
            'event' => 'validation_failed',
            'data' => [
                'errors' => $errors,
                'payload' => $payload,
            ],
        ]);
    }
}
