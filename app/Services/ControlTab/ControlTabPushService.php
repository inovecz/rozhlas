<?php

declare(strict_types=1);

namespace App\Services\ControlTab;

use App\Services\ControlTabBridge;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ControlTabPushService
{
    public function __construct(
        private readonly ControlTabBridge $bridge = new ControlTabBridge(),
    ) {
    }

    /**
     * @param array<int, string|null> $fields
     * @param array<string, mixed> $options
     */
    public function sendFields(array $fields, array $options = []): void
    {
        if ($fields === []) {
            return;
        }

        $normalised = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $normalised[$key] = $value;
                continue;
            }

            if (is_string($key) && is_numeric($key)) {
                $normalised[(int) $key] = $value;
            }
        }

        $fields = array_map(static fn ($value) => $value === null ? '' : (string) $value, $normalised);

        if ($fields === []) {
            return;
        }

        $commandOptions = $this->mergeDefaults($options);

        try {
            $this->bridge->sendFields($fields, $commandOptions);
        } catch (ProcessFailedException $exception) {
            Log::error('Control Tab push failed', [
                'fields' => $fields,
                'options' => $commandOptions,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Control Tab push threw unexpected exception', [
                'fields' => $fields,
                'options' => $commandOptions,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Convenience method for pushing a single field update.
     */
    public function setField(int $fieldId, ?string $text, array $options = []): void
    {
        $this->sendFields([$fieldId => $text], $options);
    }

    private function mergeDefaults(array $options): array
    {
        $defaults = [
            'screen' => config('control_tab.default_screen', 3),
            'panel' => config('control_tab.default_panel', 1),
        ];

        return array_filter($options + $defaults, static fn ($value) => $value !== null && $value !== '');
    }
}
