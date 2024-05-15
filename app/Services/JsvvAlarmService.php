<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JsvvAlarm;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\JsvvAlarmSaveRequest;

class JsvvAlarmService extends Service
{
    public function saveJsvvAlarmPost(JsvvAlarmSaveRequest $request, JsvvAlarm $jsvvAlarm = null): string
    {
        $data = [
            'name' => $request->input('name'),
            'sequence' => $request->input('sequence'),
            'button' => $request->input('button'),
            'mobile_button' => $request->input('mobile_button'),
        ];

        return $this->saveJsvvAlarm($data, $jsvvAlarm);
    }

    /** @param  array{name: string, sequence?: string, button?: int, mobile_button?: int}  $data */
    public function saveJsvvAlarm(array $data, JsvvAlarm $jsvvAlarm = null): string
    {
        $sequence = $data['sequence'] ?? null;
        unset($data['sequence']);

        if (!$jsvvAlarm) {
            $jsvvAlarm = JsvvAlarm::create($data);
        } else {
            $jsvvAlarm->update($data);
        }
        $jsvvAlarm->sequence = $sequence;
        $jsvvAlarm->save();

        return $this->setStatus('SAVED');
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'SAVED' => $this->setResponseMessage('response.saved'),
            default => $this->notSpecifiedError(),
        };
    }
}