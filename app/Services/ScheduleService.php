<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ScheduleSaveRequest;
use App\Http\Requests\CheckTimeConflictRequest;

class ScheduleService extends Service
{
    public function savePost(ScheduleSaveRequest $request, Schedule $schedule = null): string
    {
        $data = [
            'title' => $request->input('title'),
            'scheduled_at' => Carbon::parse($request->input('scheduled_at')),
            'is_repeating' => $request->input('is_repeating'),
            'intro_id' => $request->input('intro_id'),
            'opening_id' => $request->input('opening_id'),
            'common_ids' => $request->input('common_ids'),
            'outro_id' => $request->input('outro_id'),
            'closing_id' => $request->input('closing_id'),
        ];

        return $this->save($data, $schedule);
    }

    /** @param  array{title: string, scheduled_at: Carbon, is_repeating: bool, intro_id?: int, opening_id?: int, common_ids: array<int, int>, outro_id?: int, closing_id: int}  $data */
    public function save(array $data, Schedule $schedule = null): string
    {
        if (!$schedule) {
            $schedule = Schedule::create($data);
        } else {
            $schedule->update($data);
        }

        $this->extraInfo = $schedule->getToArray();
        return $this->setStatus('SAVED');
    }

    public function checkTimeConflictPost(CheckTimeConflictRequest $request): string
    {
        $scheduledAt = Carbon::parse($request->input('datetime'));
        $duration = $request->input('duration', 0);
        $schedule = Schedule::find($request->input('schedule_id'));
        return $this->checkTimeConflict($scheduledAt, $duration, $schedule);
    }

    public function checkTimeConflict(Carbon $startAt, int $duration = 0, Schedule $schedule = null): string
    {
        $endAt = $startAt->copy()->addSeconds($duration);
        $conflictExists = Schedule::when($schedule !== null, static function ($query) use ($schedule) {
            $query->where('id', '!=', $schedule->getId());
        })->where(static function ($query) use ($startAt, $endAt) {
            $query->whereBetween('scheduled_at', [$startAt, $endAt])
                ->orWhereBetween('end_at', [$startAt, $endAt])
                ->orWhere(static function ($query) use ($startAt, $endAt) {
                    $query->where('scheduled_at', '<=', $startAt)
                        ->where('end_at', '>=', $endAt);
                });
        })->exists();
        return $conflictExists ? $this->setStatus('CONFLICT') : $this->setStatus('NO_CONFLICT');
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'SAVED' => $this->setResponseMessage('response.saved'),
            'CONFLICT' => $this->setResponseMessage('response.time_conflict'),
            'NO_CONFLICT' => $this->setResponseMessage('response.no_time_conflict'),
            default => $this->notSpecifiedError(),
        };
    }
}