<?php

declare(strict_types=1);

namespace App\Console\Commands\Port;

use Illuminate\Console\Command;

class ExpectCommand extends Command
{
    protected $signature = 'port:expect {device : Cesta k sériovému portu (PTY)} {pattern : Regulární výraz} {--timeout=5 : Časový limit v sekundách}';

    protected $description = 'Čte z portu dokud neodpovídá regulárnímu výrazu nebo nevyprší časový limit.';

    public function handle(): int
    {
        $device = (string) $this->argument('device');
        $pattern = (string) $this->argument('pattern');
        $timeout = max(0.1, (float) $this->option('timeout'));

        $resource = @fopen($device, 'rb');
        if ($resource === false) {
            $this->error(sprintf('Port %s se nepodařilo otevřít.', $device));
            return self::FAILURE;
        }

        if (!flock($resource, LOCK_EX | LOCK_NB)) {
            fclose($resource);
            $this->error(sprintf('Port %s je aktuálně používán.', $device));
            return self::FAILURE;
        }

        try {
            stream_set_blocking($resource, false);
            $buffer = '';
            $start = microtime(true);

            while ((microtime(true) - $start) < $timeout) {
                $chunk = fread($resource, 4096);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;
                    if (@preg_match($pattern, $buffer) === 1) {
                        $this->info('Výraz nalezen.');
                        $this->line($buffer);
                        return self::SUCCESS;
                    }
                }

                usleep(100_000);
            }

            $this->error('Časový limit vypršel bez shody.');
            return self::FAILURE;
        } finally {
            flock($resource, LOCK_UN);
            fclose($resource);
        }
    }
}
