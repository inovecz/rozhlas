<?php

declare(strict_types=1);

namespace App\Console\Commands\Port;

use Illuminate\Console\Command;

class SendCommand extends Command
{
    protected $signature = 'port:send {device : Cesta k sériovému portu nebo souboru} {data : Data k odeslání} {--newline : Přidá znak konce řádku na konec}';

    protected $description = 'Odešle textová data do zvoleného sériového portu (PTY).';

    public function handle(): int
    {
        $device = (string) $this->argument('device');
        $payload = (string) $this->argument('data');
        if ($this->option('newline')) {
            $payload .= PHP_EOL;
        }

        $resource = @fopen($device, 'wb');
        if ($resource === false) {
            $this->error(sprintf('Port %s se nepodařilo otevřít pro zápis.', $device));
            return self::FAILURE;
        }

        if (!flock($resource, LOCK_EX | LOCK_NB)) {
            fclose($resource);
            $this->error(sprintf('Port %s je aktuálně používán.', $device));
            return self::FAILURE;
        }

        try {
            $written = fwrite($resource, $payload);
            if ($written === false) {
                $this->error('Zápis do portu selhal.');
                return self::FAILURE;
            }

            $this->info(sprintf('Odesláno %d bajtů do %s.', $written, $device));
            return self::SUCCESS;
        } finally {
            flock($resource, LOCK_UN);
            fclose($resource);
        }
    }
}
