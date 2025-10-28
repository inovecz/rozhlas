<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JsvvAlarm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\JsvvAlarmDurationService;

class JsvvAlarmResource extends JsonResource
{
    protected static ?JsvvAlarmDurationService $durationService = null;

    public function toArray(Request $request): array
    {
        /** @var $this JsvvAlarm */
        $payload = $this->getToArray();

        $service = self::$durationService ??= app(JsvvAlarmDurationService::class);
        $estimatedDuration = $service->estimate($this->resource);

        return array_merge($payload, [
            'estimated_duration_seconds' => $estimatedDuration,
        ]);
    }
}
