<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class ControlTabEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $deviceId,
        public readonly int|string|null $controlId,
        public readonly ?int $screenId,
        public readonly ?int $panelId,
        public readonly mixed $data,
        public readonly ?string $raw,
        public readonly ?CarbonImmutable $timestamp,
        public readonly array $originalPayload,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        if (isset($payload['event']) && is_array($payload['event'])) {
            return self::fromStructuredPayload($payload);
        }

        if (isset($payload['type'])) {
            return self::fromLegacyPayload($payload);
        }

        throw new InvalidArgumentException('Control Tab payload is missing event definition.');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fromStructuredPayload(array $payload): self
    {
        $event = $payload['event'];
        $type = strtolower((string) Arr::get($event, 'type', ''));
        if ($type === '') {
            throw new InvalidArgumentException('Control Tab event type is required.');
        }

        $controlId = Arr::get($event, 'control_id', Arr::get($event, 'controlId'));
        $screenId = self::toNullableInt(Arr::get($event, 'screen_id', Arr::get($event, 'screenId')));
        $panelId = self::toNullableInt(Arr::get($event, 'panel_id', Arr::get($event, 'panelId')));

        return new self(
            type: $type,
            deviceId: (string) Arr::get($payload, 'device_id', Arr::get($payload, 'deviceId', 'default')),
            controlId: is_scalar($controlId) ? $controlId : null,
            screenId: $screenId,
            panelId: $panelId,
            data: Arr::get($event, 'data'),
            raw: Arr::get($payload, 'raw'),
            timestamp: self::parseTimestamp($payload['ts'] ?? $payload['timestamp'] ?? null),
            originalPayload: $payload,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fromLegacyPayload(array $payload): self
    {
        $type = strtolower((string) Arr::get($payload, 'type'));
        $controlId = Arr::get($payload, 'button_id');
        if ($controlId === null) {
            $controlId = Arr::get($payload, 'buttonId');
        }
        if ($controlId === null) {
            $controlId = Arr::get($payload, 'field_id', Arr::get($payload, 'fieldId'));
        }
        if ($type === '') {
            throw new InvalidArgumentException('Control Tab event type is required.');
        }

        return new self(
            type: $type,
            deviceId: 'default',
            controlId: is_scalar($controlId) ? $controlId : null,
            screenId: self::toNullableInt(Arr::get($payload, 'screen')),
            panelId: self::toNullableInt(Arr::get($payload, 'panel')),
            data: null,
            raw: null,
            timestamp: null,
            originalPayload: $payload,
        );
    }

    private static function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
