<?php

declare(strict_types=1);

namespace App\Console\Commands\Audio;

use App\Services\Audio\AudioIoService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class SetOutputCommand extends Command
{
    protected $signature = 'audio:output
        {identifier : Identifikátor výstupu, např. lineout, hdmi nebo auto}
    ';

    protected $description = 'Přepne audio výstup pomocí ALSA mixeru.';

    public function handle(AudioIoService $service): int
    {
        $identifier = strtolower((string) $this->argument('identifier'));

        try {
            $status = $service->setOutput($identifier);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error(sprintf('Přepnutí výstupu selhalo: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        $current = Arr::get($status, 'current.output');
        $label = $current['label'] ?? ($current['id'] ?? $identifier);

        $this->info(sprintf('Výstup byl přepnut na "%s".', $label));

        return self::SUCCESS;
    }
}
