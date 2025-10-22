<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\JsvvMessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use JsonException;

class ProcessJsvvMessage extends Command
{
    protected $signature = 'jsvv:process-message {payload? : JSON payload; if omitted, command will read from STDIN}';

    protected $description = 'Ingest JSVV message payload and dispatch downstream workflows';

    public function __construct(private readonly JsvvMessageService $messageService = new JsvvMessageService())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payloadArgument = $this->argument('payload');
        $payloadString = $payloadArgument ?? $this->readStdIn();

        if ($payloadString === null || trim($payloadString) === '') {
            $this->error('Empty payload; provide JSON argument or pipe data via STDIN.');
            return self::FAILURE;
        }

        try {
            $decoded = json_decode($payloadString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid JSON payload: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $items = $this->normalizePayload($decoded);
        $processed = 0;
        $duplicates = 0;

        foreach ($items as $item) {
            try {
                $result = $this->messageService->ingest($item);
            } catch (ValidationException $exception) {
                $this->warn('Validation failed: ' . json_encode($exception->errors(), JSON_THROW_ON_ERROR));
                continue;
            }

            $processed++;
            if (Arr::get($result, 'duplicate') === true) {
                $duplicates++;
            }
        }

        $this->info(sprintf(
            'Processed %d message(s); duplicates: %d.',
            $processed,
            $duplicates
        ));

        return self::SUCCESS;
    }

    private function readStdIn(): ?string
    {
        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            return null;
        }

        $content = stream_get_contents($stdin) ?: null;
        fclose($stdin);

        return $content;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, array<string, mixed>>
     */
    private function normalizePayload(array $decoded): array
    {
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return array_values(array_filter(
                $decoded['items'],
                static fn ($item): bool => is_array($item)
            ));
        }

        return [$decoded];
    }
}
