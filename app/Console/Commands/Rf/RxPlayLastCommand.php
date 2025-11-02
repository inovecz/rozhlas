<?php

declare(strict_types=1);

namespace App\Console\Commands\Rf;

use App\Services\RF\RfBus;
use Illuminate\Console\Command;

class RxPlayLastCommand extends Command
{
    protected $signature = 'rf:rx-play-last {--priority=polling : Priorita požadavku ve frontě RF sběrnice}';

    protected $description = 'Spustí přehrání posledního záznamu na přijímači (RxControl).';

    public function __construct(private readonly RfBus $rfBus = new RfBus())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $priorityOption = $this->option('priority');
            $priority = is_string($priorityOption) && trim($priorityOption) !== '' ? $priorityOption : null;
            $this->rfBus->rxPlayLast($priority);
        } catch (\Throwable $exception) {
            $this->error(sprintf('Požadavek na přehrání selhal: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        $this->info('Přijímači byl odeslán požadavek na přehrání posledního záznamu.');
        return self::SUCCESS;
    }
}
