<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JsvvAlarm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JsvvAlarmResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this JsvvAlarm */
        return $this->getToArray();
    }
}
