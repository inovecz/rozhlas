<?php

declare(strict_types=1);

namespace App\Console\Commands\Modbus;

use App\Libraries\PythonClient;
use Illuminate\Console\Command;

class TestWriteCommand extends Command
{
    protected $signature = 'modbus:test-write {address : Adresa registru (např. 0x4035)} {values* : Hodnoty k zapsání}';

    protected $description = 'Zapíše hodnotu/e do Modbus registru pomocí python-clientu.';

    public function __construct(private readonly PythonClient $client = new PythonClient())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (($this->argument('values') ?? []) === []) {
            $this->error('Musíte zadat alespoň jednu hodnotu.');
            return self::FAILURE;
        }

        [$address, $unit] = $this->parseAddress((string) $this->argument('address'));
        $values = array_map([$this, 'parseValue'], (array) $this->argument('values'));

        try {
            $response = $this->client->runModbus('write-registers', [
                'address' => $address,
                'values' => $values,
                'unit-id' => $unit,
            ]);
        } catch (\Throwable $exception) {
            $this->error('Volání python-client selhalo: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function parseValue(string $input): int
    {
        $trimmed = trim($input);
        if (str_starts_with(strtolower($trimmed), '0x')) {
            return (int) hexdec(substr($trimmed, 2));
        }

        return (int) $trimmed;
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
