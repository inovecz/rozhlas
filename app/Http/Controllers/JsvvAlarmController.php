<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\JsvvAlarm;
use App\Models\JsvvAudio;
use Illuminate\Http\JsonResponse;
use App\Services\JsvvAlarmService;
use App\Http\Resources\JsvvAlarmResource;
use App\Http\Requests\JsvvAlarmSaveRequest;
use App\Http\Requests\JsvvAudiosSaveRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JsvvAlarmController extends Controller
{
    public function getAll(): AnonymousResourceCollection
    {
        $jsvvAlarms = JsvvAlarm::orderBy('button')->get();
        return JsvvAlarmResource::collection($jsvvAlarms);
    }

    public function getJsvvAlarm(JsvvAlarm $jsvvAlarm): JsvvAlarmResource
    {
        return new JsvvAlarmResource($jsvvAlarm);
    }

    public function getAudios(): JsonResponse
    {
        $audios = JsvvAudio::with('file')->orderBy('symbol')
            ->get()
            ->map(fn(JsvvAudio $audio) => $audio->getToArray())
            ->values()
            ->toArray();
        return $this->success($audios);
    }

    public function saveAudios(JsvvAudiosSaveRequest $request): JsonResponse
    {
        $audios = $request->validated();
        JsvvAudio::upsert($audios, ['symbol'], ['name', 'type', 'group', 'source', 'file_id']);
        return $this->success();
    }

    public function saveJsvvAlarm(JsvvAlarmSaveRequest $request, JsvvAlarm $jsvvAlarm = null): JsonResponse
    {
        $jsvvAlarmService = new JsvvAlarmService();
        $jsvvAlarmService->saveJsvvAlarmPost($request, $jsvvAlarm);
        return $jsvvAlarmService->getResponse();
    }
}
