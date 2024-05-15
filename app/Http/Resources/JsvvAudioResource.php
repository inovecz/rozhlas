<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JsvvAudio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JsvvAudioResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this JsvvAudio */
        return $this->getToArray();
    }
}
