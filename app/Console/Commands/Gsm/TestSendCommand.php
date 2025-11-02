<?php

declare(strict_types=1);

namespace App\Console\Commands\Gsm;

use App\Services\GsmStreamService;
use Illuminate\Console\Command;
use JsonException;

class TestSendCommand extends Command
{
    protected $signature = 'gsm:test-send {payload? : JSON událost} {--stdin : Čtení ze STDIN}';

    protected $description = 'Simuluje GSM událost (ringing/accepted/finished) a vypíše odpověď.';

    public function __construct(private readonly GsmStreamService $service = new GsmStreamService())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->resolvePayload();
        if ($payload === null) {
            $this->error('Nebyl dodán JSON payload.');
            return self::FAILURE;
        }

        try {
            $data = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Neplatný JSON: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $response = $this->service->handleIncomingCall(is_array($data) ? $data : []);
        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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

        $arg = $this->argument('payload');
        if (is_string($arg)) {
            if (is_file($arg)) {
                return file_get_contents($arg) ?: null;
            }
            return $arg;
        }

        return null;
    }
}
