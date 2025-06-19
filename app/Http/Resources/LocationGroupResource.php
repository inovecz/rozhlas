<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\LocationGroup;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationGroupResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this LocationGroup */
        return $this->getToArray();
    }
}
