<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use App\Services\ScheduleService;
use App\Http\Requests\ListRequest;
use App\Http\Resources\ScheduleResource;
use App\Http\Requests\ScheduleSaveRequest;
use App\Http\Requests\CheckTimeConflictRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $files = Schedule::query()
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('title', 'like', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    $query->orderBy($item['column'], $item['dir']);
                }
                return $query;
            })->when($request->input('filter'), static function ($query, $filter) {
                if (!empty($filter)) {
                    foreach ($filter as $item) {
                        $query->where($item['column'], $item['value']);
                    }
                }
                return $query;
            })
            ->paginate($request->input('length', 10));

        return ScheduleResource::collection($files);
    }

    public function get(Schedule $schedule): ScheduleResource
    {
        return new ScheduleResource($schedule);
    }

    public function save(ScheduleSaveRequest $request, Schedule $schedule = null): JsonResponse
    {
        $scheduleService = new ScheduleService();
        $scheduleService->savePost($request, $schedule);
        return $scheduleService->getResponse();
    }

    public function delete(Schedule $schedule): JsonResponse
    {
        $schedule->delete();
        return $this->success();
    }

    public function checkTimeConflict(CheckTimeConflictRequest $request): JsonResponse
    {
        $scheduleService = new ScheduleService();
        $scheduleService->checkTimeConflictPost($request);
        return $scheduleService->getResponse();
    }
}
