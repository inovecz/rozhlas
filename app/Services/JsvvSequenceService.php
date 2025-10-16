<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;
use App\Models\JsvvEvent;
use App\Models\JsvvSequence;
use App\Models\JsvvSequenceItem;
use App\Services\StreamOrchestrator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class JsvvSequenceService extends Service
{
    public function __construct(
        private readonly PythonClient $client = new PythonClient(),
        private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator(),
    ) {
        parent::__construct();
    }

    public function plan(array $items, array $options = []): array
    {
        $response = $this->client->planJsvvSequence($items, $options);
        $data = $response['json']['data'] ?? $response['json'] ?? [];

        return DB::transaction(function () use ($data, $items, $options): array {
            $sequence = JsvvSequence::create([
                'items' => $items,
                'options' => $options,
                'priority' => Arr::get($options, 'priority'),
                'status' => 'planned',
            ]);

            foreach ($items as $index => $item) {
                JsvvSequenceItem::create([
                    'sequence_id' => $sequence->id,
                    'position' => $index,
                    'category' => $item['category'] ?? 'verbal',
                    'slot' => $item['slot'],
                    'voice' => $item['voice'] ?? null,
                    'repeat' => (int) max(1, $item['repeat'] ?? 1),
                    'metadata' => $item,
                ]);
            }

            return $sequence->fresh()->toArray() + ['plan' => $data];
        });
    }

    public function trigger(string $sequenceId): array
    {
        $sequence = JsvvSequence::query()->find($sequenceId);
        if ($sequence === null) {
            return [
                'status' => 'not_found',
                'id' => $sequenceId,
            ];
        }

        $sequence->update([
            'status' => 'running',
            'triggered_at' => now(),
        ]);

        $items = $sequence->sequenceItems()->orderBy('position')->get();
        $this->orchestrator->start([
            'source' => 'jsvv',
            'route' => Arr::get($sequence->options, 'route', []),
            'zones' => Arr::get($sequence->options, 'zones', []),
            'options' => ['priority' => $sequence->priority],
        ]);

        foreach ($items as $item) {
            $category = $item->category;
            $slot = $item->slot;
            $repeat = (int) max(1, $item->repeat);

            for ($i = 0; $i < $repeat; $i++) {
                if ($category === 'siren') {
                    $this->client->triggerJsvvFrame('SIREN', [(string) $slot], false, true);
                } else {
                    $voice = $item->voice ?? 'male';
                    $this->client->triggerJsvvFrame('VERBAL', [(string) $slot, $voice], false, true);
                }
            }
        }

        $this->orchestrator->stop('jsvv_sequence_completed');

        $sequence->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return $sequence->fresh()->toArray();
    }

    public function listSequences(): array
    {
        return JsvvSequence::query()->with('sequenceItems')->orderByDesc('created_at')->limit(100)->get()->toArray();
    }

    public function getAssets(?string $category = null, ?int $slot = null, ?string $voice = null): array
    {
        $response = $this->client->listJsvvAssets(
            slot: $slot,
            voice: $voice,
            includePaths: false,
            category: $category,
        );

        return $response['json']['data']['assets']
            ?? $response['json']['assets']
            ?? $response['json']
            ?? [];
    }

    public function recordEvent(array $payload): void
    {
        JsvvEvent::create([
            'command' => Arr::get($payload, 'command'),
            'mid' => Arr::get($payload, 'mid'),
            'priority' => Arr::get($payload, 'priority'),
            'duplicate' => (bool) Arr::get($payload, 'duplicate', false),
            'payload' => $payload,
        ]);
    }
}
