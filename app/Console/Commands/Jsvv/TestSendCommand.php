<?php

declare(strict_types=1);

namespace App\Console\Commands\Jsvv;

use App\Libraries\PythonClient;
use Illuminate\Console\Command;
use JsonException;

class TestSendCommand extends Command
{
    protected $signature = 'jsvv:test-send {mid : MID příkaz} {--params=} {--send : Odeslat do sítě (jinak pouze simulace)} {--no-crc : Nezahrnovat CRC}' ;

    protected $description = 'Vyvolá python-client trigger pro JSVV rámec (pro testování parseru).';

    public function __construct(private readonly PythonClient $client = new PythonClient())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mid = (string) $this->argument('mid');
        $params = $this->parseParams((string) $this->option('params'));

        try {
            $response = $this->client->triggerJsvvFrame(
                $mid,
                $params,
                $this->option('send') === true,
                !$this->option('no-crc')
            );
        } catch (\Throwable $exception) {
            $this->error('Volání python-client selhalo: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseParams(string $input): array
    {
        if ($input === '') {
            return [];
        }

        if (str_starts_with($input, '{')) {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
                return $decoded;
            } catch (JsonException $exception) {
                $this->warn('Parametry nejsou validní JSON, zkusím fallback. (' . $exception->getMessage() . ')');
            }
        }

        $pairs = [];
        foreach (explode(',', $input) as $item) {
            if (!str_contains($item, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $item, 2));
            if ($key === '') {
                continue;
            }
            $pairs[$key] = is_numeric($value) ? (float) $value : $value;
        }

        return $pairs;
    }
}
