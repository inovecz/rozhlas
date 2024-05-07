<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\ContactGroup;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactGroupResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this ContactGroup */
        return $this->getToArray();
    }
}
