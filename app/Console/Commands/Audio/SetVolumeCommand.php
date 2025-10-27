<?php

declare(strict_types=1);

namespace App\Console\Commands\Audio;

use App\Services\Audio\AudioIoService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SetVolumeCommand extends Command
{
    protected $signature = 'audio:volume
        {scope : Identifikátor hlasitosti (např. master, input, output)}
        {value? : Cílová hodnota v procentech (0-100)}
        {--mute : Zapnout mute (nastaví příslušný switch do stavu off)}
        {--unmute : Vypnout mute (nastaví příslušný switch do stavu on)}
    ';

    protected $description = 'Nastaví hlasitost nebo mute stav pomocí ALSA mixeru.';

    public function handle(AudioIoService $service): int
    {
        $scope = strtolower((string) $this->argument('scope'));
        $valueArgument = $this->argument('value');

        if ($this->option('mute') && $this->option('unmute')) {
            $this->error('Nelze současně použít volby --mute a --unmute.');
            return self::FAILURE;
        }

        $mute = null;
        if ($this->option('mute')) {
            $mute = true;
        } elseif ($this->option('unmute')) {
            $mute = false;
        }

        $value = null;
        if ($valueArgument !== null) {
            if (!is_numeric($valueArgument)) {
                $this->error('Hodnota hlasitosti musí být číslo v rozsahu 0-100.');
                return self::FAILURE;
            }
            $value = (float) $valueArgument;
            if ($value < 0 || $value > 100) {
                $this->error('Hodnota hlasitosti musí být v rozsahu 0-100.');
                return self::FAILURE;
            }
        }

        try {
            $result = $service->setVolume($scope, $value, $mute);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error(sprintf('Nastavení hlasitosti selhalo: %s', $exception->getMessage()));
            return self::FAILURE;
        }

        $label = $result['label'] ?? $scope;
        $valueText = $result['value'] !== null
            ? sprintf('%s %%', number_format((float) $result['value'], 1))
            : 'neznámá';

        $muteState = $result['mute'];
        if ($muteState === true) {
            $muteLabel = 'ztišeno';
        } elseif ($muteState === false) {
            $muteLabel = 'aktivní';
        } else {
            $muteLabel = 'neznámý stav';
        }

        $this->info(sprintf('Hlasitost "%s": %s (%s)', $label, $valueText, $muteLabel));

        return self::SUCCESS;
    }
}
