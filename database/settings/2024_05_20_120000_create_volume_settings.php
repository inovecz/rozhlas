<?php

use Illuminate\Support\Arr;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $config = config('volume', []);

        $this->migrator->add('volume.inputs', $this->extractDefaults(Arr::get($config, 'inputs.items', [])));
        $this->migrator->add('volume.outputs', $this->extractDefaults(Arr::get($config, 'outputs.items', [])));
        $this->migrator->add('volume.playback', $this->extractDefaults(Arr::get($config, 'playback.items', [])));
    }

    /**
     * @param array<string, array{default: float|int|string}> $items
     * @return array<string, float>
     */
    private function extractDefaults(array $items): array
    {
        $defaults = [];
        foreach ($items as $key => $definition) {
            $defaults[$key] = (float) ($definition['default'] ?? 0.0);
        }

        return $defaults;
    }
};
