<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BroadcastLockedException;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastSession;
use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use App\Models\LocationGroup;
use App\Services\Audio\AlsamixerService;
use App\Services\ControlTab\ButtonMappingRepository;
use App\Services\VolumeManager;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ControlTabService extends Service
{
    private const SELECTED_ALARM_CACHE_KEY = 'control_tab:selected_jsvv_alarm';

    public function __construct(
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly JsvvSequenceService $jsvvSequenceService = new JsvvSequenceService(),
        private readonly ButtonMappingRepository $buttonMappings = new ButtonMappingRepository(),
    ) {
        parent::__construct();
    }

    public function handleButtonPress(int $buttonId, array $context = []): array
    {
        $context['button_id'] = $buttonId;
        $mapping = $this->resolveButtonMapping($buttonId, $context);

        if ($mapping === null) {
            return [
                'status' => 'unsupported',
                'message' => sprintf('Neznámé tlačítko (%d).', $buttonId),
            ];
        }

        return match ($mapping['action'] ?? null) {
            'start_or_trigger_selected_jsvv_alarm' => $this->startOrTriggerSelectedJsvvAlarm($mapping, $context),
            'start_stream' => $this->startStream($mapping, $context),
            'stop_stream' => $this->stopStream($mapping),
            'trigger_jsvv_alarm' => $this->triggerJsvvAlarm($mapping, $context),
            'trigger_selected_jsvv_alarm' => $this->triggerSelectedJsvvAlarm($mapping, $context),
            'select_jsvv_alarm' => $this->selectJsvvAlarm($mapping),
            'stop_jsvv' => $this->stopJsvv($mapping),
            'ack_message' => $this->acknowledgeMessage($mapping),
            'lock_panel' => $this->lockPanel($mapping),
            'cancel_selection' => $this->cancelSelection($mapping),
            'cancel_selection_stop_stream' => $this->cancelSelectionAndStopStream($mapping),
            default => [
                'status' => 'unsupported',
                'message' => sprintf('Akce %s zatím není implementována.', $mapping['action'] ?? 'neznámá'),
            ],
        };
    }

    private function resolveButtonMapping(int $buttonId, array &$context): ?array
    {
        $alarm = JsvvAlarm::query()->where('button', $buttonId)->first();
        if ($alarm !== null) {
            $context['jsvv_alarm'] = $alarm;

            return [
                'action' => 'trigger_jsvv_alarm',
                'button' => $alarm->getButton(),
                'label' => $alarm->getName(),
            ];
        }

        $mapping = $this->buttonMappings->find($buttonId);

        if ($mapping === null) {
            return null;
        }

        return $mapping;
    }

    public function handlePanelLoaded(int $screen, int $panel): array
    {
        return [
            'status' => 'ok',
            'screen' => $screen,
            'panel' => $panel,
        ];
    }

    public function handleTextRequest(int $fieldId): array
    {
        $text = $this->resolveTextField($fieldId);

        if ($text === null) {
            return [
                'status' => 'unsupported',
                'field_id' => $fieldId,
                'text' => '',
            ];
        }

        return [
            'status' => 'ok',
            'field_id' => $fieldId,
            'text' => $text,
        ];
    }

    private function startStream(array $config, array $context = []): array
    {
        $defaults = config('control_tab.defaults', []);
        $selections = $context['selections'] ?? [];
        $selectedLocalities = is_array($selections) ? ($selections['localities'] ?? []) : [];
        $selectedJingle = is_array($selections) ? ($selections['jingle'] ?? null) : null;

        $selectedGroupIds = [];
        if (is_array($selectedLocalities) && $selectedLocalities !== []) {
            $groups = LocationGroup::query()
                ->whereIn('name', $selectedLocalities)
                ->get(['id', 'name'])
                ->keyBy('name');

            foreach ($selectedLocalities as $name) {
                $group = $groups->get($name);
                if ($group !== null) {
                    $selectedGroupIds[] = (int) $group->id;
                }
            }
        }

        $payload = [
            'source' => $config['source'] ?? 'microphone',
            'route' => $config['route'] ?? Arr::get($defaults, 'route', []),
            'locations' => $selectedGroupIds !== [] ? $selectedGroupIds : ($config['locations'] ?? Arr::get($defaults, 'locations', [])),
            'nests' => $config['nests'] ?? Arr::get($defaults, 'nests', []),
            'options' => array_merge(
                Arr::get($defaults, 'options', []),
                $config['options'] ?? [],
                ['origin' => 'control_tab']
            ),
        ];

        $payload['options'] = $this->augmentStreamOptions($payload['options'], $config, $payload['source']);
        $volume = $this->resolveVolumeLevel($config, $payload['options']);
        if ($volume !== null) {
            $payload['options']['volume'] = $volume;
        }

        $this->prepareAudioInput($payload['source'], $config, $volume, $payload['options']);

        $audioInputId = $payload['options']['audioInputId'] ?? null;
        if (is_string($audioInputId) && $audioInputId !== '') {
            $payload['mixer'] = [
                'identifier' => $audioInputId,
                'source' => $payload['source'],
            ];
            if ($volume !== null) {
                $payload['mixer']['volume'] = $volume;
            }
        }

        if ($selectedLocalities !== []) {
            $payload['options']['_control_tab_selected_localities'] = $selectedLocalities;
        }
        if (is_string($selectedJingle) && $selectedJingle !== '') {
            $payload['options']['_control_tab_selected_jingle'] = $selectedJingle;
        }

        $generalZone = (int) config('control_tab.general_zone', 0);
        if ($generalZone > 0 && !isset($payload['options']['_control_tab_force_zone'])) {
            $payload['options']['_control_tab_force_zone'] = $generalZone;
        }
        $modbusUnitId = (int) config('control_tab.modbus_unit_id');
        if ($modbusUnitId > 0) {
            $payload['options']['modbusUnitId'] = $modbusUnitId;
        }

        try {
            $session = $this->orchestrator->start($payload);
        } catch (BroadcastLockedException $exception) {
            return [
                'status' => 'blocked',
                'message' => 'Vysílání nelze spustit – probíhá poplach JSVV.',
            ];
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'invalid_request',
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'success_message', 'Vysílání bylo spuštěno přes Control Tab.'),
            'session' => $session,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     */
    private function augmentStreamOptions(array $options, array $config, string $source): array
    {
        $inputOverride = Arr::get($config, 'audio_input')
            ?? Arr::get($config, 'mixer_input')
            ?? Arr::get($options, 'audio_input_id')
            ?? Arr::get($options, 'audioInputId')
            ?? config('control_tab.mixer.input');

        if (is_string($inputOverride) && trim($inputOverride) !== '') {
            $options['audioInputId'] = $inputOverride;
        } elseif (!isset($options['audioInputId'])) {
            $options['audioInputId'] = $source;
        }

        $outputOverride = Arr::get($config, 'audio_output')
            ?? Arr::get($options, 'audio_output_id')
            ?? Arr::get($options, 'audioOutputId')
            ?? config('control_tab.mixer.output');

        if (is_string($outputOverride) && trim($outputOverride) !== '') {
            $options['audioOutputId'] = $outputOverride;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    private function resolveVolumeLevel(array $config, array $options): ?float
    {
        $candidates = [
            Arr::get($config, 'volume'),
            Arr::get($config, 'options.volume'),
            Arr::get($options, 'volume'),
            config('control_tab.mixer.volume'),
            config('control_tab.default_volume'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeVolumeLevel($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeVolumeLevel(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $numeric = (float) $value;
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !is_numeric($trimmed)) {
                return null;
            }
            $numeric = (float) $trimmed;
        } else {
            return null;
        }

        if (!is_finite($numeric)) {
            return null;
        }

        if ($numeric < 0.0) {
            $numeric = 0.0;
        } elseif ($numeric > 100.0) {
            $numeric = 100.0;
        }

        return $numeric;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    private function prepareAudioInput(string $source, array $config, ?float $volume, array $options): void
    {
        if (!(bool) config('control_tab.mixer.enabled', false)) {
            return;
        }

        try {
            /** @var AlsamixerService $alsamixer */
            $alsamixer = app(AlsamixerService::class);
        } catch (\Throwable $exception) {
            Log::debug('Control Tab: ALSA mixer service unavailable.', [
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        if (!$alsamixer->isEnabled()) {
            return;
        }

        $inputId = Arr::get($options, 'audioInputId');
        if (!is_string($inputId) || trim($inputId) === '') {
            $inputId = $source;
        }

        $targetVolume = $volume ?? $this->resolveVolumeLevel($config, $options);
        Log::info('Control Tab: selecting ALSA input', [
            'input' => $inputId,
            'source' => $source,
            'volume' => $targetVolume,
        ]);
        $selected = $alsamixer->selectInput($inputId, $targetVolume);
        if (!$selected && $inputId !== $source) {
            $alsamixer->selectInput($source, $targetVolume);
        }

        if ($targetVolume === null) {
            return;
        }

        $channel = $alsamixer->volumeChannelForInput($inputId);
        if ($channel === null) {
            $channel = $alsamixer->volumeChannelForInput($source);
        }
        if ($channel === null) {
            return;
        }

        try {
            /** @var VolumeManager $volumeManager */
            $volumeManager = app(VolumeManager::class);
            $volumeManager->applyRuntimeLevel($channel['group'], $channel['channel'], $targetVolume);
        } catch (\Throwable $exception) {
            Log::debug('Control Tab: runtime volume update failed.', [
                'input' => $inputId,
                'channel_group' => $channel['group'] ?? null,
                'channel_id' => $channel['channel'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function startOrTriggerSelectedJsvvAlarm(array $config, array $context = []): array
    {
        $selected = $this->resolveSelectedAlarm();
        $selectedButton = (int) ($selected['button'] ?? 0);

        if ($selectedButton > 0) {
            $payload = [
                'button' => $selectedButton,
            ];

            $label = $selected['label'] ?? ($config['label'] ?? null);
            if ($label !== null) {
                $payload['label'] = $label;
            }

            $result = $this->triggerJsvvAlarm($payload, $context);

            if (!in_array($result['status'] ?? null, [
                'error',
                'invalid_request',
                'unsupported',
                'validation_error',
                'jsvv_active',
                'not_found',
            ], true)) {
                Cache::forget(self::SELECTED_ALARM_CACHE_KEY);
            }

            return $result;
        }

        return $this->startStream($config, $context);
    }

    private function stopStream(array $config = []): array
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null) {
            return [
                'status' => 'idle',
                'message' => Arr::get($config, 'idle_message', 'Žádné vysílání neběží.'),
            ];
        }

        try {
            Log::info('Control Tab: stopping live broadcast', [
                'session_id' => $session->id,
                'source' => $session->source,
            ]);
            $stoppedSession = $this->orchestrator->stop('control_tab_stop');
        } catch (\Throwable $exception) {
            Log::error('Control Tab: stop broadcast failed', [
                'session_id' => $session->id,
                'source' => $session->source,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Nepodařilo se ukončit vysílání.',
            ];
        }

        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'success_message', 'Vysílání bylo zastaveno.'),
            'session' => $stoppedSession,
        ];
    }

    private function triggerJsvvAlarm(array $config, array $context = []): array
    {
        $button = (int) ($config['button'] ?? ($context['button_id'] ?? 0));
        if ($button <= 0) {
            return [
                'status' => 'error',
                'message' => 'Konfigurace poplachu je neplatná.',
            ];
        }

        $alarm = $context['jsvv_alarm'] ?? null;
        if (!$alarm instanceof JsvvAlarm) {
            $alarm = JsvvAlarm::query()->where('button', $button)->first();
        }
        if ($alarm === null) {
            return [
                'status' => 'error',
                'message' => sprintf('Poplach pro tlačítko %d není nakonfigurován.', $button),
            ];
        }

        $items = $this->buildSequenceItems($alarm);
        if ($items === []) {
            return [
                'status' => 'error',
                'message' => 'Poplach nemá žádné kroky.',
            ];
        }

        if ($this->shouldAnimateTestOnControlTab($alarm, $items) && $this->hasActiveBroadcast()) {
            return [
                'status' => 'jsvv_active',
                'message' => 'Povel TEST lze vykonat pouze v klidovém stavu ústředny.',
            ];
        }

        $label = $config['label'] ?? $alarm->name ?? null;

        $sequence = $this->jsvvSequenceService->plan($items, [
            'priority' => $alarm->priority ?? 'P2',
            'zones' => $alarm->zones ?? [],
        ]);

        $result = $this->jsvvSequenceService->trigger($sequence['id']);

        if ($this->shouldAnimateTestOnControlTab($alarm, $items)) {
            $result['control_tab'] = $this->buildTestControlTabPayload();
        }

        if ($result['status'] === 'not_found') {
            return [
                'status' => 'error',
                'message' => 'Plánovaný poplach se nepodařilo spustit.',
            ];
        }

        $message = match ($result['status']) {
            'queued' => $label !== null
                ? sprintf('Poplach "%s" byl zařazen do fronty.', $label)
                : 'Poplach byl zařazen do fronty.',
            'running', 'completed' => $label !== null
                ? sprintf('Poplach "%s" byl spuštěn.', $label)
                : 'Poplach byl spuštěn.',
            default => $label !== null
                ? sprintf('Poplach "%s" byl vyhodnocen.', $label)
                : 'Poplach byl vyhodnocen.',
        };

        return [
            'status' => $result['status'],
            'message' => $message,
            'sequence' => $result,
        ];
    }

    private function stopJsvv(array $config = []): array
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null || $session->source !== 'jsvv') {
            return [
                'status' => 'idle',
                'message' => Arr::get($config, 'idle_message', 'Poplach JSVV není aktivní.'),
            ];
        }

        $this->orchestrator->stop('control_tab_jsvv_stop');

        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'success_message', 'Poplach JSVV byl zastaven.'),
        ];
    }

    private function triggerSelectedJsvvAlarm(array $config, array $context = []): array
    {
        $selected = $this->resolveSelectedAlarm();
        $button = (int) ($selected['button'] ?? $config['fallback_button'] ?? $config['button'] ?? 0);

        if ($button <= 0) {
            return [
                'status' => 'error',
                'message' => 'Vyberte nejprve typ poplachu.',
            ];
        }

        $payload = $config;
        $payload['button'] = $button;
        if (!isset($payload['label']) && isset($selected['label'])) {
            $payload['label'] = $selected['label'];
        }

        return $this->triggerJsvvAlarm($payload, $context);
    }

    private function selectJsvvAlarm(array $config): array
    {
        $button = (int) ($config['button'] ?? 0);
        if ($button <= 0) {
            return [
                'status' => 'error',
                'message' => 'Konfigurace poplachu je neplatná.',
            ];
        }

        $label = $config['label'] ?? null;

        Cache::put(
            self::SELECTED_ALARM_CACHE_KEY,
            [
                'button' => $button,
                'label' => $label,
            ],
            now()->addMinutes(10)
        );

        $message = Arr::get(
            $config,
            'success_message',
            $label !== null
                ? sprintf('Vybrán poplach "%s". Stiskněte "Spustit poplach JSVV".', $label)
                : 'Poplach byl vybrán. Stiskněte "Spustit poplach JSVV".'
        );

        return [
            'status' => 'ok',
            'message' => $message,
            'selected_button' => $button,
        ];
    }

    private function acknowledgeMessage(array $config): array
    {
        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'message', 'Akce byla potvrzena.'),
        ];
    }

    private function lockPanel(array $config): array
    {
        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'message', 'Panel byl uzamčen.'),
        ];
    }

    private function cancelSelection(array $config): array
    {
        Cache::forget(self::SELECTED_ALARM_CACHE_KEY);

        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'message', 'Výběr poplachu byl zrušen.'),
        ];
    }

    private function cancelSelectionAndStopStream(array $config): array
    {
        Cache::forget(self::SELECTED_ALARM_CACHE_KEY);

        $stopResult = $this->stopStream($config);
        $status = $stopResult['status'] ?? 'ok';

        if (in_array($status, ['error', 'invalid_request', 'blocked', 'unsupported'], true)) {
            return $stopResult;
        }

        if ($status === 'idle') {
            return [
                'status' => 'idle',
                'message' => Arr::get(
                    $config,
                    'idle_message',
                    Arr::get($stopResult, 'message', 'Žádné vysílání neběží.')
                ),
            ];
        }

        return [
            'status' => $status,
            'message' => Arr::get(
                $config,
                'success_message',
                Arr::get($stopResult, 'message', 'Přímé hlášení bylo ukončeno.')
            ),
        ];
    }

    private function resolveSelectedAlarm(): ?array
    {
        $selected = Cache::get(self::SELECTED_ALARM_CACHE_KEY);

        if ($selected instanceof Arrayable) {
            $selected = $selected->toArray();
        } elseif ($selected instanceof \JsonSerializable) {
            $selected = (array) $selected;
        }

        if ($selected instanceof \stdClass) {
            $selected = (array) $selected;
        }

        if (is_string($selected)) {
            $decoded = json_decode($selected, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selected = $decoded;
            } else {
                $unserialized = @unserialize($selected);
                if ($unserialized !== false || $selected === 'b:0;') {
                    $selected = $unserialized;
                }
            }
        }

        return is_array($selected) ? $selected : null;
    }

    private function hasActiveBroadcast(): bool
    {
        return BroadcastSession::query()
            ->where('status', 'running')
            ->exists();
    }

    private function buildSequenceItems(JsvvAlarm $alarm): array
    {
        $symbols = collect([
            $alarm->sequence_1,
            $alarm->sequence_2,
            $alarm->sequence_3,
            $alarm->sequence_4,
        ])
            ->filter(static fn($symbol) => $symbol !== null)
            ->map(static fn($symbol) => (string) $symbol)
            ->values();

        if ($symbols->isEmpty()) {
            return [];
        }

        $audios = JsvvAudio::query()
            ->whereIn('symbol', $symbols)
            ->get()
            ->keyBy('symbol');

        return $symbols
            ->map(function (string $symbol) use ($audios): array {
                $audio = $audios->get($symbol);
                $category = $audio?->getGroup()->value ?? 'VERBAL';
                $category = strtolower($category);
                if ($category !== 'siren') {
                    $category = 'verbal';
                }

                return [
                    'slot' => $symbol,
                    'category' => $category,
                    'repeat' => 1,
                    'symbol' => $symbol,
                ];
            })
            ->toArray();
    }

    private function shouldAnimateTestOnControlTab(JsvvAlarm $alarm, array $sequenceItems): bool
    {
        $sequenceString = strtoupper((string) ($alarm->getSequence() ?? ''));
        if (str_contains($sequenceString, 'TEST')) {
            return true;
        }

        $name = strtoupper((string) ($alarm->name ?? ''));
        if (str_contains($name, 'TEST')) {
            return true;
        }

        foreach ($sequenceItems as $item) {
            $symbol = strtoupper((string) ($item['symbol'] ?? ''));
            if ($symbol === 'TEST') {
                return true;
            }
        }

        return false;
    }

    private function buildTestControlTabPayload(): array
    {
        $fieldId = (int) config('control_tab.test_progress_field', 1);

        $frames = [
            ['text' => 'TEST spuštěn', 'delay_ms' => 0],
            ['text' => 'TEST probíhá •', 'delay_ms' => 2000],
            ['text' => 'TEST probíhá ••', 'delay_ms' => 4000],
            ['text' => 'TEST probíhá •••', 'delay_ms' => 6000],
            ['text' => 'TEST dokončen', 'delay_ms' => 8000],
        ];

        return [
            'animations' => [
                [
                    'type' => 'progress_text',
                    'fieldId' => $fieldId,
                    'frames' => $frames,
                ],
            ],
        ];
    }

    private function renderTextField(string $callback): string
    {
        return match ($callback) {
            'status_summary' => $this->renderStatusSummary(),
            'running_duration' => $this->renderRunningDuration(),
            'running_length' => $this->renderRunningLength(),
            'planned_duration' => $this->renderPlannedDuration(),
            'active_locations' => $this->renderActiveLocations(),
            'active_playlist_item' => $this->renderActivePlaylistItem(),
            default => '',
        };
    }

    private function resolveTextField(int $fieldId): ?string
    {
        $entry = config("control_tab.text_fields.{$fieldId}");

        if ($entry === null) {
            return null;
        }

        if (is_callable($entry)) {
            try {
                $value = $entry();
                return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $exception) {
                return null;
            }
        }

        if (is_string($entry)) {
            return $this->renderTextField($entry);
        }

        return null;
    }

    private function renderStatusSummary(): string
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null) {
            return 'Ústředna je připravena.';
        }

        return match ($session->source) {
            'jsvv' => 'Probíhá poplach JSVV.',
            'gsm' => 'Hlášení přes GSM.',
            'recorded_playlist' => 'Přehrává se playlist.',
            default => 'Probíhá živé vysílání.',
        };
    }

    private function renderRunningDuration(): string
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();
        if ($session === null || $session->started_at === null) {
            return '--:--';
        }

        $diff = now()->diffAsCarbonInterval($session->started_at)->cascade();
        return $diff->format('%H:%I:%S');
    }

    private function renderRunningLength(): string
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();
        if ($session === null || $session->started_at === null) {
            return '0 s';
        }
        $seconds = now()->diffInSeconds($session->started_at);
        return sprintf('%d s', $seconds);
    }

    private function renderPlannedDuration(): string
    {
        $playlist = BroadcastPlaylist::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest('created_at')
            ->first();

        if ($playlist === null) {
            return 'Žádné plánované hlášení.';
        }

        $options = $playlist->options ?? [];
        $planned = Arr::get($options, 'plannedDuration');
        if ($planned === null) {
            return 'Délka neznámá.';
        }

        return sprintf('%d s', (int) $planned);
    }

    private function renderActiveLocations(): string
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null) {
            $names = LocationGroup::query()
                ->where(function ($query): void {
                    $query
                        ->whereNull('is_hidden')
                        ->orWhere('is_hidden', false);
                })
                ->orderBy('name')
                ->pluck('name')
                ->map(static fn ($name) => (string) $name)
                ->implode("\n");

            return $names !== '' ? $names : 'Bez omezení.';
        }

        $locations = Arr::get($session->options ?? [], '_labels.locations', []);

        if (empty($locations)) {
            return 'Bez omezení.';
        }

        return implode("\n", array_map(
            static fn(array $item) => $item['name'] ?? ('ID ' . ($item['id'] ?? '?')),
            $locations
        ));
    }

    private function renderActivePlaylistItem(): string
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        $playlist = Arr::get($session?->options ?? [], 'playlist.items.0.title');
        if ($playlist === null) {
            return '';
        }

        return (string) $playlist;
    }
}
