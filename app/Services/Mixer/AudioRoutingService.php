<?php

declare(strict_types=1);

namespace App\Services\Mixer;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class AudioRoutingService
{
    public function __construct(
        private readonly MixerController $mixer = new MixerController(),
    ) {
    }

    public function apply(?string $inputId, ?string $outputId, array $context = []): void
    {
        $routing = config('broadcast.mixer.routing', []);

        $inputMap = is_array($routing) ? Arr::get($routing, 'inputs', []) : [];
        $outputMap = is_array($routing) ? Arr::get($routing, 'outputs', []) : [];

        $this->applySelection('input', $inputId, $inputMap, $context);
        $this->applySelection('output', $outputId, $outputMap, $context);
    }

    private function applySelection(string $type, ?string $identifier, array $map, array $context): void
    {
        if ($identifier === null || $identifier === '' || $identifier === 'default') {
            return;
        }

        $mergedContext = array_merge($context, [
            'audio_type' => $type,
            'audio_id' => $identifier,
        ]);

        if (isset($map[$identifier])) {
            $this->mixer->runCustomCommand(
                $map[$identifier],
                $mergedContext,
                sprintf('apply %s routing %s', $type, $identifier),
            );
            return;
        }

        if (str_starts_with($identifier, 'pulse:')) {
            $name = substr($identifier, strlen('pulse:'));
            if ($name === '') {
                return;
            }

            $command = [
                'pactl',
                $type === 'input' ? 'set-default-source' : 'set-default-sink',
                $name,
            ];
            $this->mixer->runCustomCommand(
                $command,
                array_merge($mergedContext, ['pulse_name' => $name]),
                sprintf('set pulse %s %s', $type, $name),
                true,
            );
            return;
        }

        if (str_starts_with($identifier, 'alsa:')) {
            Log::info('ALSA audio routing selected without configured command.', [
                'type' => $type,
                'id' => $identifier,
            ]);
            return;
        }

        Log::info('Audio routing selection skipped (no handler).', [
            'type' => $type,
            'id' => $identifier,
        ]);
    }
}
