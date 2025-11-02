<?php

declare(strict_types=1);

namespace App\Console\Commands\Rf;

use App\Services\RF\RfBus;
use Illuminate\Console\Command;

class ReadStatusCommand extends Command
{
    protected $signature = 'rf:read-status {--json : Vytiskne výstup ve formátu JSON} {--priority=polling : Priorita požadavku ve frontě RF sběrnice}';

    protected $description = 'Načte základní stav RF modulu (Tx/Rx/Status/Error).';

    public function __construct(private readonly RfBus $rfBus = new RfBus())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $priorityOption = $this->option('priority');
            $priority = is_string($priorityOption) && trim($priorityOption) !== '' ? $priorityOption : null;
            $status = $this->rfBus->readStatus($priority);
        } catch (\Throwable $exception) {
            $this->error(sprintf('Čtení stavu selhalo: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['klíč', 'hodnota'],
                collect($status)->map(fn ($value, $key) => [$key, is_array($value) ? json_encode($value) : $value])->all()
            );
        }

        return self::SUCCESS;
    }
}
