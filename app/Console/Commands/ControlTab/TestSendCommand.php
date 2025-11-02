<?php

declare(strict_types=1);

namespace App\Console\Commands\ControlTab;

use App\Services\ControlTabService;
use Illuminate\Console\Command;
use JsonException;

class TestSendCommand extends Command
{
    protected $signature = 'ctab:test-send {button : ID tlačítka} {--context=}';

    protected $description = 'Simuluje stisk tlačítka Control Tab a vypíše výsledek.';

    public function __construct(private readonly ControlTabService $service = new ControlTabService())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $button = (int) $this->argument('button');
        $context = $this->parseContext((string) $this->option('context'));

        $response = $this->service->handleButtonPress($button, $context);
        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseContext(string $input): array
    {
        if ($input === '') {
            return [];
        }

        try {
            $decoded = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            $this->warn('Context není validní JSON, bude ignorován.');
            return [];
        }
    }
}
