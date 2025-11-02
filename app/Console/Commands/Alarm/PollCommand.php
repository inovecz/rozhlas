<?php

declare(strict_types=1);

namespace App\Console\Commands\Alarm;

use App\Services\RF\RfBus;
use Illuminate\Console\Command;

class PollCommand extends Command
{
    protected $signature = 'alarm:poll {--limit=8 : Maximální počet načtených záznamů} {--json : Výstup ve formátu JSON} {--payload-stdin : Načte předaný JSON payload ze STDIN místo Modbusu} {--priority=polling : Priorita požadavku ve frontě RF sběrnice}';

    protected $description = 'Jednorázově načte alarm buffer z Modbus registrů (0x3000–0x3009).';

    public function __construct(private readonly RfBus $rfBus = new RfBus())
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? $limit : null;
        $priorityOption = $this->option('priority');
        $priority = is_string($priorityOption) && trim($priorityOption) !== '' ? $priorityOption : null;

        $result = $this->option('payload-stdin') ? $this->readPayloadFromStdin() : null;
        if ($result === null) {
            try {
                $result = $this->rfBus->readBuffersLifo($limit, $priority);
            } catch (\Throwable $exception) {
                $this->error('Čtení alarm bufferu selhalo: ' . $exception->getMessage());
                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $frames = $result['frames'] ?? ($result['data'] ?? []);
        if ($frames === []) {
            $this->info('Žádné položky v alarm bufferu.');
        } else {
            $this->table(
                ['index', 'hex'],
                array_map(static function ($value, $index) {
                    return [$index, sprintf('0x%04X', $value & 0xFFFF)];
                }, $frames, array_keys($frames))
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayloadFromStdin(): ?array
    {
        $content = stream_get_contents(STDIN);
        if ($content === false || trim($content) === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}
