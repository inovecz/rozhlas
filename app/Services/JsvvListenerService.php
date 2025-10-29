<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\JsvvMessageService;
use App\Models\Log as ActivityLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class JsvvListenerService extends Service
{
    public function __construct(
        private readonly JsvvSequenceService $sequenceService = new JsvvSequenceService(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly JsvvMessageService $messageService = new JsvvMessageService(),
    ) {
        parent::__construct();
    }

    public function handleFrame(array $payload): void
    {
        Log::info('JSVV frame received', $payload);

        $data = $payload['payload'] ?? $payload;
        $command = Arr::get($data, 'command');
        $params = Arr::get($data, 'params', []);
        $priority = Arr::get($data, 'priority');

        try {
            $this->messageService->ingest($data + [
                'rawMessage' => Arr::get($payload, 'raw', Arr::get($data, 'rawMessage')),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to ingest JSVV message from listener', [
                'message' => $exception->getMessage(),
                'payload' => $data,
            ]);
        }

        if ($command === 'STOP') {
            $this->orchestrator->stop('jsvv_stop_command');

            $this->recordCommandLog(
                $command,
                $priority,
                $params,
                $data,
                'stop_executed',
                'Příkaz STOP byl zpracován a probíhající přenos ukončen.'
            );
            return;
        }

        $sequenceItems = $this->buildSequenceItemsFromCommand($command, $params);
        if ($sequenceItems === []) {
            $this->recordCommandLog(
                $command,
                $priority,
                $params,
                $data,
                'ignored',
                'Příkaz nebyl mapován na žádnou sekvenci ani akci.'
            );
            return;
        }

        $options = $this->deriveSequenceOptions($command, $params, $priority);

        try {
            $sequence = $this->sequenceService->plan($sequenceItems, $options);
            $sequenceId = (string) (Arr::get($sequence, 'id') ?? Arr::get($sequence, 'sequence.id'));
            if ($sequenceId !== '') {
                $this->recordCommandLog(
                    $command,
                    $priority,
                    $params,
                    $data,
                    'dispatched',
                    sprintf('Sekvence #%s byla naplánována a odeslána ke spuštění.', $sequenceId)
                );
                $this->sequenceService->trigger($sequenceId);
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to dispatch JSVV sequence from listener.', [
                'command' => $command,
                'payload' => $payload,
                'exception' => $exception->getMessage(),
            ]);

            $this->recordCommandLog(
                $command,
                $priority,
                $params,
                $data,
                'failed',
                'Sekvenci se nepodařilo spustit: ' . $exception->getMessage()
            );
        }
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'SAVED' => $this->setResponseMessage('response.saved'),
            default => $this->notSpecifiedError(),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function buildSequenceItemsFromCommand(?string $command, array $params): array
    {
        $normalized = strtoupper((string) $command);
        $repeat = max(1, (int) ($params['repeat'] ?? $params['repeats'] ?? 1));

        if ($normalized === 'SIREN_SIGNAL') {
            $slot = $this->extractNumericParam($params, ['signalType', 'slot', 'tokens.0']);
            if ($slot === null) {
                return [];
            }

            return [[
                'slot' => $slot,
                'category' => 'siren',
                'repeat' => $repeat,
            ]];
        }

        if (in_array($normalized, ['GONG', 'VERBAL_INFO', 'VERBAL'], true)) {
            $slot = $this->extractNumericParam($params, ['gongType', 'slot', 'tokens.0']);
            if ($slot === null) {
                return [];
            }

            return [[
                'slot' => $slot,
                'category' => 'verbal',
                'repeat' => $repeat,
            ]];
        }

        if (in_array($normalized, ['PLAY_SEQUENCE', 'SEQUENCE'], true)) {
            $sequenceString = Arr::get($params, 'sequence') ?? Arr::get($params, 'symbols');
            if (!is_string($sequenceString) || trim($sequenceString) === '') {
                return [];
            }

            $items = [];
            $symbols = preg_split('//u', $sequenceString, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($symbols as $symbol) {
                $slot = $this->extractNumericParam([$symbol], [0]);
                if ($slot !== null) {
                    $items[] = [
                        'slot' => $slot,
                        'category' => 'verbal',
                        'repeat' => 1,
                    ];
                }
            }

            return $items;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int|string, mixed> $keys
     */
    private function extractNumericParam(array $params, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = Arr::get($params, (string) $key);
            if (is_array($value)) {
                $candidate = $this->extractNumericParam($value, array_keys($value));
                if ($candidate !== null) {
                    return $candidate;
                }
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
            if (is_string($value) && preg_match('/(-?\d+)/', $value, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deriveSequenceOptions(?string $command, array $params, ?string $priority): array
    {
        $options = [];
        if ($priority !== null) {
            $options['priority'] = strtoupper($priority);
        }

        $zones = $this->normalizeNumericList($params['zones'] ?? $params['zone'] ?? null);
        if ($zones !== []) {
            $options['zones'] = $zones;
        }

        $locations = $this->normalizeNumericList($params['locations'] ?? $params['location'] ?? null);
        if ($locations !== []) {
            $options['locations'] = $locations;
        }

        $holdSeconds = $params['holdSeconds'] ?? $params['hold'] ?? null;
        if (is_numeric($holdSeconds)) {
            $options['holdSeconds'] = max(0, (float) $holdSeconds);
        }

        $audioInput = $params['audioInput'] ?? $params['audio_input'] ?? null;
        if (is_string($audioInput) && $audioInput !== '') {
            $options['audioInputId'] = strtolower($audioInput);
        }

        $audioOutput = $params['audioOutput'] ?? $params['audio_output'] ?? null;
        if (is_string($audioOutput) && $audioOutput !== '') {
            $options['audioOutputId'] = strtolower($audioOutput);
        }

        $playback = $params['playback'] ?? $params['source'] ?? null;
        if (is_string($playback) && $playback !== '') {
            $options['playbackSource'] = strtolower($playback);
        }

        $frequencyCandidates = [
            $params['frequency_hz'] ?? null,
            $params['frequencyHz'] ?? null,
            $params['frequency'] ?? null,
            $params['frequency_mhz'] ?? null,
            $params['frequencyMhz'] ?? null,
        ];
        foreach ($frequencyCandidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                $options['frequency'] = $candidate;
                break;
            }
        }

        $normalizedCommand = strtoupper((string) $command);
        $defaultOutput = 'lineout';

        $ensureOutput = static function (array &$target, string $outputId) {
            if (!isset($target['audioOutputId']) || !is_string($target['audioOutputId']) || $target['audioOutputId'] === '') {
                $target['audioOutputId'] = $outputId;
            }
        };

        $ensureOutput($options, $defaultOutput);

        $preferInput = static function (array &$target, string $inputId): void {
            if (!isset($target['audioInputId']) || !is_string($target['audioInputId']) || $target['audioInputId'] === '') {
                $target['audioInputId'] = $inputId;
            }
        };

        $preferSource = static function (array &$target, string $sourceId): void {
            if (!isset($target['playbackSource']) || !is_string($target['playbackSource']) || $target['playbackSource'] === '') {
                $target['playbackSource'] = $sourceId;
            }
        };

        switch ($normalizedCommand) {
            case 'RADIO_BRIDGE':
                $preferInput($options, 'fm');
                $preferSource($options, 'fm_radio');
                break;
            case 'REMOTE_VOICE':
                $preferInput($options, 'jsvv_remote_voice');
                $preferSource($options, 'jsvv_remote_voice');
                break;
            case 'LOCAL_VOICE':
                $preferInput($options, 'jsvv_local_voice');
                $preferSource($options, 'jsvv_local_voice');
                break;
            case 'EXTERNAL_AUDIO_PRIMARY':
                $preferInput($options, 'jsvv_external_primary');
                $preferSource($options, 'jsvv_external_primary');
                break;
            case 'EXTERNAL_AUDIO_SECONDARY':
                $preferInput($options, 'jsvv_external_secondary');
                $preferSource($options, 'jsvv_external_secondary');
                break;
        }

        return $options;
    }

    private function recordCommandLog(?string $command, ?string $priority, array $params, array $payload, string $status, ?string $note = null): void
    {
        $commandLabel = strtoupper((string) $command);
        $priorityLabel = $priority !== null ? (string) $priority : 'neuvedeno';

        ActivityLog::create([
            'type' => 'jsvv',
            'title' => sprintf('JSVV příkaz: %s', $commandLabel),
            'description' => $note ?? sprintf('Příkaz byl zpracován se statusem %s (priorita %s).', $status, $priorityLabel),
            'data' => [
                'command' => $command,
                'priority' => $priority,
                'status' => $status,
                'params' => $params,
                'payload' => $payload,
            ],
        ]);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeNumericList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_numeric($value)) {
            return [(int) $value];
        }

        if (is_array($value)) {
            $entries = $value;
        } elseif (is_string($value)) {
            $entries = preg_split('/[,\s;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            if (is_numeric($entry)) {
                $result[] = (int) $entry;
            } elseif (is_string($entry) && preg_match('/(-?\d+)/', $entry, $matches) === 1) {
                $result[] = (int) $matches[1];
            }
        }

        return array_values(array_unique($result));
    }
}
