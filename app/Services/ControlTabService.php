<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BroadcastLockedException;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastSession;
use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ControlTabService extends Service
{
    private const SELECTED_ALARM_CACHE_KEY = 'control_tab:selected_jsvv_alarm';

    public function __construct(
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly JsvvSequenceService $jsvvSequenceService = new JsvvSequenceService(),
    ) {
        parent::__construct();
    }

    public function handleButtonPress(int $buttonId, array $context = []): array
    {
        $context['button_id'] = $buttonId;
        $mapping = config("control_tab.buttons.{$buttonId}");

        $alarm = JsvvAlarm::query()->where('button', $buttonId)->first();
        if ($alarm !== null) {
            $context['jsvv_alarm'] = $alarm;
            $currentAction = $mapping['action'] ?? null;
            if ($mapping === null || $currentAction === 'trigger_jsvv_alarm') {
                $mapping = array_merge($mapping ?? [], [
                    'action' => 'trigger_jsvv_alarm',
                    'button' => $alarm->getButton(),
                    'label' => $mapping['label'] ?? $alarm->getName(),
                ]);
            }
        }

        if ($mapping === null && $alarm === null) {
            return [
                'status' => 'unsupported',
                'message' => sprintf('Neznámé tlačítko (%d).', $buttonId),
            ];
        }

        return match ($mapping['action'] ?? null) {
            'start_stream' => $this->startStream($mapping),
            'stop_stream' => $this->stopStream($mapping),
            'trigger_jsvv_alarm' => $this->triggerJsvvAlarm($mapping, $context),
            'trigger_selected_jsvv_alarm' => $this->triggerSelectedJsvvAlarm($mapping, $context),
            'select_jsvv_alarm' => $this->selectJsvvAlarm($mapping),
            'stop_jsvv' => $this->stopJsvv($mapping),
            'ack_message' => $this->acknowledgeMessage($mapping),
            'lock_panel' => $this->lockPanel($mapping),
            'cancel_selection' => $this->cancelSelection($mapping),
            default => [
                'status' => 'unsupported',
                'message' => sprintf('Akce %s zatím není implementována.', $mapping['action'] ?? 'neznámá'),
            ],
        };
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

    private function startStream(array $config): array
    {
        $defaults = config('control_tab.defaults', []);
        $payload = [
            'source' => $config['source'] ?? 'microphone',
            'route' => $config['route'] ?? Arr::get($defaults, 'route', []),
            'locations' => $config['locations'] ?? Arr::get($defaults, 'locations', []),
            'nests' => $config['nests'] ?? Arr::get($defaults, 'nests', []),
            'options' => array_merge(
                Arr::get($defaults, 'options', []),
                $config['options'] ?? [],
                ['origin' => 'control_tab']
            ),
        ];

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

        $this->orchestrator->stop('control_tab_stop');

        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'success_message', 'Vysílání bylo zastaveno.'),
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

        $label = $config['label'] ?? $alarm->name ?? null;

        $sequence = $this->jsvvSequenceService->plan($items, [
            'priority' => $alarm->priority ?? 'P2',
            'zones' => $alarm->zones ?? [],
        ]);

        $result = $this->jsvvSequenceService->trigger($sequence['id']);

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
        /** @var array<string, mixed>|null $selected */
        $selected = Cache::get(self::SELECTED_ALARM_CACHE_KEY);
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
                ];
            })
            ->toArray();
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
        $locations = Arr::get($session?->options ?? [], '_labels.locations', []);

        if (empty($locations)) {
            return 'Bez omezení.';
        }

        return implode(', ', array_map(
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
            return 'Žádná znělka není vybrána.';
        }

        return (string) $playlist;
    }
}
