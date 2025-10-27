<?php

declare(strict_types=1);

namespace App\Console\Commands\Audio;

use App\Services\Audio\AudioIoService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class SetInputCommand extends Command
{
    protected $signature = 'audio:input
        {identifier : Identifikátor vstupu, např. system, mic, fm, control_box, aux2}
    ';

    protected $description = 'Přepne audio vstup pomocí ALSA mixeru.';

    public function handle(AudioIoService $service): int
    {
        $identifier = strtolower((string) $this->argument('identifier'));

        try {
            $status = $service->setInput($identifier);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error(sprintf('Přepnutí vstupu selhalo: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        $current = Arr::get($status, 'current.input');
        $label = $current['label'] ?? ($current['id'] ?? $identifier);

        $this->info(sprintf('Vstup byl přepnut na "%s".', $label));

        return self::SUCCESS;
    }
}
