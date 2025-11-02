<?php

declare(strict_types=1);

namespace App\Console\Commands\Modbus;

use App\Libraries\PythonClient;
use Illuminate\Console\Command;

class TestReadCommand extends Command
{
    protected $signature = 'modbus:test-read {address : Adresa registru (např. 0x4035)} {count=1 : Počet registrů}';

    protected $description = 'Přečte raw Modbus registr(y) pomocí python-clientu.';

    public function __construct(private readonly PythonClient $client = new PythonClient())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        [$address, $unit] = $this->parseAddress((string) $this->argument('address'));
        $count = max(1, (int) $this->argument('count'));

        try {
            $response = $this->client->runModbus('read-register', [
                'address' => $address,
                'count' => $count,
                'unit-id' => $unit,
            ]);
        } catch (\Throwable $exception) {
            $this->error('Volání python-client selhalo: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    /**
     * @return array{0:int,1:int|null}
     */
    private function parseAddress(string $input): array
    {
        $unitId = null;
        if (str_contains($input, '@')) {
            [$input, $unitRaw] = explode('@', $input, 2);
            if (is_numeric($unitRaw)) {
                $unitId = (int) $unitRaw;
            }
        }

        $trimmed = trim($input);
        if (str_starts_with(strtolower($trimmed), '0x')) {
            return [(int) hexdec(substr($trimmed, 2)), $unitId];
        }

        return [(int) $trimmed, $unitId];
    }
}
