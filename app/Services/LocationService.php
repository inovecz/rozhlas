<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocationGroup;
use App\Enums\SubtoneTypeEnum;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\LocationGroupSaveRequest;

class LocationService extends Service
{
    public function saveLocationGroupPost(LocationGroupSaveRequest $request, LocationGroup $locationGroup = null): string
    {
        $data = [
            'name' => $request->input('name'),
            'is_hidden' => $request->input('is_hidden'),
            'subtone_type' => $request->input('subtone_type'),
            'subtone_data' => $request->input('subtone_data'),
            'init_audio_id' => $request->input('init_audio')['id'] ?? null,
            'exit_audio_id' => $request->input('exit_audio')['id'] ?? null,
            'timing' => $request->input('timing'),
        ];

        return $this->saveLocationGroup($data, $locationGroup);
    }

    /** @param  array{name: string, is_hidden: boolean, subtone_type: SubtoneTypeEnum, subtone_data: array{listen: int[], record: int[]}, init_audio_id?: int, exit_audio_id?: int, timing: array }  $data */
    public function saveLocationGroup(array $data, LocationGroup $locationGroup = null): string
    {
        if (!$locationGroup) {
            LocationGroup::create($data);
        } else {
            $locationGroup->update($data);
        }

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