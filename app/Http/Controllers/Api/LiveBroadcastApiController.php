<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StreamOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class LiveBroadcastApiController extends Controller
{
    public function __construct(private readonly StreamOrchestrator $orchestrator = new StreamOrchestrator())
    {
    }

    public function start(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string'],
            'route' => ['sometimes', 'array'],
            'route.*' => ['integer'],
            'zones' => ['sometimes', 'array'],
            'zones.*' => ['integer'],
            'locations' => ['sometimes', 'array'],
            'locations.*' => ['integer'],
            'options' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $locations = Arr::get($payload, 'locations', Arr::get($payload, 'zones', []));
        $payload['locations'] = $locations;
        $payload['zones'] = $locations;
        $session = $this->orchestrator->start($payload);

        return response()->json(['session' => $session]);
    }

    public function stop(Request $request): JsonResponse
    {
        $reason = $request->string('reason')->toString() ?: null;
        $session = $this->orchestrator->stop($reason);

        return response()->json(['session' => $session]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->orchestrator->getStatusDetails());
    }

    public function playlist(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recordings' => ['required', 'array', 'min:1'],
            'recordings.*.id' => ['required'],
            'recordings.*.durationSeconds' => ['sometimes', 'integer', 'min:1'],
            'recordings.*.gain' => ['sometimes', 'numeric'],
            'recordings.*.gapMs' => ['sometimes', 'integer', 'min:0'],
            'route' => ['sometimes', 'array'],
            'route.*' => ['integer'],
            'zones' => ['sometimes', 'array'],
            'zones.*' => ['integer'],
            'locations' => ['sometimes', 'array'],
            'locations.*' => ['integer'],
            'options' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $locations = Arr::get($payload, 'locations', Arr::get($payload, 'zones', []));
        $playlist = $this->orchestrator->enqueuePlaylist(
            Arr::get($payload, 'recordings'),
            Arr::get($payload, 'route', []),
            $locations,
            Arr::get($payload, 'options', []),
        );

        return response()->json(['playlist' => $playlist]);
    }

    public function cancelPlaylist(string $playlistId): JsonResponse
    {
        $playlist = $this->orchestrator->cancelPlaylist($playlistId);
        return response()->json(['playlist' => $playlist]);
    }

    public function sources(): JsonResponse
    {
        return response()->json(['sources' => $this->orchestrator->listSources()]);
    }
}
