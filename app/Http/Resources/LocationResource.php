<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this Location */
        return $this->getToArray();
    }
}
