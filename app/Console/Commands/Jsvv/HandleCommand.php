<?php

declare(strict_types=1);

namespace App\Console\Commands\Jsvv;

use App\Services\JsvvMessageService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

class HandleCommand extends Command
{
    protected $signature = 'jsvv:handle {payload? : JSON payload nebo cesta k souboru} {--stdin : Číst payload ze STDIN}';

    protected $description = 'Zpracuje JSVV zprávu pomocí JsvvMessageService (pro testy a démony).';

    public function __construct(private readonly JsvvMessageService $messageService = new JsvvMessageService())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payloadString = $this->resolvePayload();
        if ($payloadString === null) {
            $this->error('JSON payload nebyl dodán.');
            return self::FAILURE;
        }

        try {
            $decoded = json_decode($payloadString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Neplatný JSON: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $payloads = $this->normalizePayload($decoded);
        $handled = 0;
        $duplicates = 0;

        foreach ($payloads as $payload) {
            try {
                $result = $this->messageService->ingest($payload);
                $handled++;
                if (($result['duplicate'] ?? false) === true) {
                    $duplicates++;
                }
            } catch (ValidationException $exception) {
                $this->warn('Validace selhala: ' . json_encode($exception->errors(), JSON_UNESCAPED_UNICODE));
            }
        }

        $this->info(sprintf('Zpracováno %d zpráv (duplicit: %d).', $handled, $duplicates));
        return self::SUCCESS;
    }

    private function resolvePayload(): ?string
    {
        if ($this->option('stdin')) {
            $stream = fopen('php://stdin', 'r');
            if ($stream !== false) {
                $content = stream_get_contents($stream) ?: null;
                fclose($stream);
                if ($content !== null && trim($content) !== '') {
                    return $content;
                }
            }
        }

        $argument = $this->argument('payload');
        if ($argument === null) {
            return null;
        }

        if (is_string($argument) && is_file($argument)) {
            return file_get_contents($argument) ?: null;
        }

        return is_string($argument) ? $argument : null;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, array<string, mixed>>
     */
    private function normalizePayload(array $decoded): array
    {
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return array_values(array_filter($decoded['items'], static fn ($item) => is_array($item)));
        }

        return [$decoded];
    }
}
