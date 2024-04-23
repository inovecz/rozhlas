<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this Log */
        return $this->getToArray();
    }
}
