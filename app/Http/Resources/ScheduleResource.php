<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this Schedule */
        return $this->getToArray();
    }
}
