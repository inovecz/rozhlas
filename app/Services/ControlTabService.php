<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BroadcastLockedException;
use App\Models\BroadcastPlaylist;
use App\Models\BroadcastSession;
use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use Illuminate\Support\Arr;

class ControlTabService extends Service
{
    public function __construct(
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
        private readonly JsvvSequenceService $jsvvSequenceService = new JsvvSequenceService(),
    ) {
        parent::__construct();
    }

    public function handleButtonPress(int $buttonId, array $context = []): array
    {
        $mapping = config("control_tab.buttons.{$buttonId}");
        if ($mapping === null) {
            return [
                'status' => 'unsupported',
                'message' => sprintf('Neznámé tlačítko (%d).', $buttonId),
            ];
        }

        return match ($mapping['action'] ?? null) {
            'start_stream' => $this->startStream($mapping),
            'stop_stream' => $this->stopStream(),
            'trigger_jsvv_alarm' => $this->triggerJsvvAlarm($mapping),
            'stop_jsvv' => $this->stopJsvv(),
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
        $callback = config("control_tab.text_fields.{$fieldId}");
        if ($callback === null) {
            return [
                'status' => 'unsupported',
                'field_id' => $fieldId,
                'text' => '',
            ];
        }

        $text = $this->renderTextField($callback);

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
            'message' => 'Vysílání bylo spuštěno přes Control Tab.',
            'session' => $session,
        ];
    }

    private function stopStream(): array
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null) {
            return [
                'status' => 'idle',
                'message' => 'Žádné vysílání neběží.',
            ];
        }

        $this->orchestrator->stop('control_tab_stop');

        return [
            'status' => 'ok',
            'message' => 'Vysílání bylo zastaveno.',
        ];
    }

    private function triggerJsvvAlarm(array $config): array
    {
        $button = (int) ($config['button'] ?? 0);
        if ($button <= 0) {
            return [
                'status' => 'error',
                'message' => 'Konfigurace poplachu je neplatná.',
            ];
        }

        $alarm = JsvvAlarm::query()->where('button', $button)->first();
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

        return [
            'status' => $result['status'],
            'message' => $result['status'] === 'queued'
                ? 'Poplach byl zařazen do fronty.'
                : 'Poplach byl spuštěn.',
            'sequence' => $result,
        ];
    }

    private function stopJsvv(): array
    {
        $session = BroadcastSession::query()
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if ($session === null || $session->source !== 'jsvv') {
            return [
                'status' => 'idle',
                'message' => 'Poplach JSVV není aktivní.',
            ];
        }

        $this->orchestrator->stop('control_tab_jsvv_stop');

        return [
            'status' => 'ok',
            'message' => 'Poplach JSVV byl zastaven.',
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
