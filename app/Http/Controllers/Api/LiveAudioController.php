<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\BroadcastLockedException;
use App\Http\Controllers\Controller;
use App\Services\Audio\AlsamixerService;
use App\Services\Audio\MixerService;
use App\Services\StreamOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class LiveAudioController extends Controller
{
    public function applySource(Request $request, AlsamixerService $alsamixer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'volume' => ['nullable', 'numeric', 'between:0,100'],
        ])->after(function ($validator) {
            /** @var \Illuminate\Validation\Validator $validator */
            $data = $validator->getData();
            $identifier = Arr::get($data, 'identifier');
            $source = Arr::get($data, 'source');

            if (!is_string($identifier) || trim($identifier) === '') {
                if (!is_string($source) || trim($source) === '') {
                    $validator->errors()->add('source', 'Musíte zadat vstup.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$alsamixer->isEnabled()) {
            return response()->json([
                'status' => 'unavailable',
                'message' => 'ALSA mixer helper není dostupný.',
            ], 503);
        }

        $identifier = $request->input('identifier');
        $source = $request->input('source');
        $resolved = is_string($identifier) && trim($identifier) !== ''
            ? $identifier
            : (is_string($source) ? $source : '');
        $resolved = strtolower(trim($resolved));
        if ($resolved === '') {
            return response()->json([
                'status' => 'invalid_identifier',
                'message' => 'Neplatný vstup.',
            ], 422);
        }

        $volume = $request->input('volume');
        $normalizedVolume = null;
        if ($volume !== null && $volume !== '') {
            $normalizedVolume = max(0.0, min(100.0, (float) $volume));
        }

        try {
            $applied = $alsamixer->selectInput($resolved, $normalizedVolume);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_identifier',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'mixer_error',
                'message' => $exception->getMessage(),
            ], 503);
        }

        if (!$applied) {
            return response()->json([
                'status' => 'mixer_error',
                'message' => 'Nepodařilo se použít zadaný vstup.',
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'applied' => [
                'source' => $resolved,
                'volume' => $normalizedVolume,
            ],
        ]);
    }

    public function selectSource(Request $request, MixerService $mixer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'volume' => ['nullable', 'numeric', 'between:0,100'],
        ])->after(function ($validator) {
            /** @var \Illuminate\Validation\Validator $validator */
            $data = $validator->getData();
            $identifier = Arr::get($data, 'identifier');
            $source = Arr::get($data, 'source');

            if (!is_string($identifier) || $identifier === '') {
                if (!is_string($source) || $source === '') {
                    $validator->errors()->add('identifier', 'Musíte zadat identifikátor vstupu.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $identifier = strtolower((string) ($request->input('identifier') ?? $request->input('source')));
        $volume = $request->input('volume');
        $normalizedVolume = null;
        if ($volume !== null && $volume !== '') {
            $normalizedVolume = max(0.0, min(100.0, (float) $volume));
        }

        try {
            $status = $mixer->selectInput($identifier, $normalizedVolume);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_identifier',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'mixer_error',
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'mixer' => $status,
        ]);
    }

    public function control(Request $request, StreamOrchestrator $orchestrator): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => ['required', 'string', Rule::in(['play', 'stop'])],
            'source' => ['required_if:action,play', 'string'],
            'route' => ['sometimes', 'array'],
            'route.*' => ['integer'],
            'locations' => ['sometimes', 'array'],
            'locations.*' => ['integer'],
            'zones' => ['sometimes', 'array'],
            'zones.*' => ['integer'],
            'nests' => ['sometimes', 'array'],
            'nests.*' => ['integer'],
            'options' => ['sometimes', 'array'],
            'reason' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $action = $request->string('action')->toString();

        if ($action === 'stop') {
            $reason = $request->string('reason')->toString();

            try {
                $session = $orchestrator->stop($reason !== '' ? $reason : null);
            } catch (RuntimeException $exception) {
                return response()->json([
                    'status' => 'control_channel_error',
                    'message' => $exception->getMessage(),
                ], 503);
            }

            return response()->json([
                'status' => 'stopped',
                'session' => $session,
            ]);
        }

        $payload = [
            'source' => $request->string('source')->toString(),
            'route' => $this->normalizeIntArray($request->input('route', [])),
            'locations' => $this->normalizeIntArray($request->input('locations', [])),
            'zones' => $this->normalizeIntArray($request->input('zones', [])),
            'nests' => $this->normalizeIntArray($request->input('nests', [])),
            'options' => $this->normalizeArray($request->input('options', [])),
        ];

        try {
            $session = $orchestrator->start($payload);
        } catch (BroadcastLockedException $exception) {
            return response()->json([
                'status' => 'jsvv_active',
                'message' => 'Nelze spustit vysílání: probíhá poplach JSVV.',
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'control_channel_error',
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'status' => 'started',
            'session' => $session,
        ]);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeIntArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $intItem = filter_var($item, FILTER_VALIDATE_INT);
            if ($intItem !== false) {
                $result[] = (int) $intItem;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return $value;
    }
}
