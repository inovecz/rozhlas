<?php

declare(strict_types=1);

namespace App\Console\Commands\Rf;

use App\Services\RF\RfBus;
use Illuminate\Console\Command;

class TxStartCommand extends Command
{
    protected $signature = 'rf:tx-start {--priority=plan : Priorita požadavku ve frontě RF sběrnice}';

    protected $description = 'Spustí RF vysílání (TxControl).';

    public function __construct(private readonly RfBus $rfBus = new RfBus())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $priorityOption = $this->option('priority');
            $priority = is_string($priorityOption) && trim($priorityOption) !== '' ? $priorityOption : null;
            $this->rfBus->txStart($priority);
        } catch (\Throwable $exception) {
            $this->error(sprintf('TxStart selhal: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        $this->info('Vysílání bylo spuštěno.');
        return self::SUCCESS;
    }
}
