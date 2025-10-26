<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ControlChannelTimeoutException;
use App\Exceptions\ControlChannelTransportException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use JsonException;

class ControlChannelTransport
{
    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutMs,
        private readonly int $retryAttempts,
        private readonly int $handshakeTimeoutMs,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ControlChannelTimeoutException
     * @throws ControlChannelTransportException
     */
    public function send(array $payload): array
    {
        $attempts = max(1, $this->retryAttempts);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->performSend($payload);
            } catch (ControlChannelTimeoutException|ControlChannelTransportException $exception) {
                $lastException = $exception;
                Log::warning('Control channel send failed', [
                    'attempt' => $attempt,
                    'endpoint' => $this->endpoint,
                    'error' => $exception->getMessage(),
                ]);

                if ($attempt === $attempts) {
                    throw $exception;
                }

                usleep($this->computeBackoffMicroseconds($attempt));
            }
        }

        throw $lastException ?? new ControlChannelTransportException('Unknown control channel transport error');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ControlChannelTimeoutException
     * @throws ControlChannelTransportException
     */
    private function performSend(array $payload): array
    {
        $start = microtime(true);
        $timeoutSeconds = $this->timeoutSeconds();
        $timeoutMicros = $this->timeoutMicros();
        $resource = @stream_socket_client(
            $this->endpoint,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($resource)) {
            throw new ControlChannelTransportException(sprintf(
                'Failed to connect to control channel %s: [%d] %s',
                $this->endpoint,
                $errno,
                $errstr
            ));
        }

        $this->expectHandshake($resource);

        stream_set_timeout($resource, $timeoutSeconds, $timeoutMicros);

        $encoded = $this->encodeJson($payload) . "\n";
        $written = fwrite($resource, $encoded);

        if ($written === false || $written < strlen($encoded)) {
            fclose($resource);
            throw new ControlChannelTransportException('Failed to write control channel request payload');
        }

        $response = fgets($resource);
        $meta = stream_get_meta_data($resource);
        fclose($resource);

        if ($response === false) {
            if (Arr::get($meta, 'timed_out') === true) {
                throw new ControlChannelTimeoutException('Control channel timed out while waiting for response');
            }

            throw new ControlChannelTransportException('Failed to read control channel response');
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ControlChannelTransportException('Control channel returned invalid JSON response', 0, $exception);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        Log::debug('Control channel response received', [
            'endpoint' => $this->endpoint,
            'duration_ms' => $durationMs,
            'response' => $decoded,
        ]);

        return $decoded;
    }

    /**
     * @param resource $resource
     *
     * @throws ControlChannelTimeoutException
     * @throws ControlChannelTransportException
     */
    private function expectHandshake($resource): void
    {
        $timeoutSeconds = max(0, (int) floor($this->handshakeTimeoutMs / 1000));
        $timeoutMicros = max(0, ($this->handshakeTimeoutMs % 1000) * 1000);
        if ($timeoutSeconds > 0 || $timeoutMicros > 0) {
            stream_set_timeout($resource, $timeoutSeconds, $timeoutMicros);
        }

        $line = fgets($resource);
        $meta = stream_get_meta_data($resource);

        if ($line === false) {
            if (Arr::get($meta, 'timed_out') === true) {
                throw new ControlChannelTimeoutException('Control channel handshake timed out');
            }

            throw new ControlChannelTransportException('Control channel handshake failed');
        }

        $handshake = trim($line);
        if ($handshake === '') {
            throw new ControlChannelTransportException('Empty handshake from control channel');
        }

        if (!in_array($handshake, ['READY', 'OK', 'HELLO'], true)) {
            throw new ControlChannelTransportException(sprintf(
                'Unexpected handshake from control channel: %s',
                $handshake
            ));
        }
    }

    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ControlChannelTransportException('Failed to encode control channel payload to JSON', 0, $exception);
        }
    }

    private function timeoutSeconds(): float
    {
        return max(0.1, $this->timeoutMs / 1000);
    }

    private function timeoutMicros(): int
    {
        $fractional = $this->timeoutMs % 1000;
        return $fractional * 1000;
    }

    private function computeBackoffMicroseconds(int $attempt): int
    {
        $base = 50_000; // 50 ms
        return $base * $attempt;
    }
}
