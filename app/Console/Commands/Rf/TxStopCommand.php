<?php

declare(strict_types=1);

namespace App\Console\Commands\Rf;

use App\Services\RF\RfBus;
use Illuminate\Console\Command;

class TxStopCommand extends Command
{
    protected $signature = 'rf:tx-stop {--priority=stop : Priorita požadavku ve frontě RF sběrnice}';

    protected $description = 'Zastaví RF vysílání (TxControl).';

    public function __construct(private readonly RfBus $rfBus = new RfBus())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $priorityOption = $this->option('priority');
            $priority = is_string($priorityOption) && trim($priorityOption) !== '' ? $priorityOption : null;
            $this->rfBus->txStop($priority);
        } catch (\Throwable $exception) {
            $this->error(sprintf('TxStop selhal: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        $this->info('Vysílání bylo zastaveno.');
        return self::SUCCESS;
    }
}
