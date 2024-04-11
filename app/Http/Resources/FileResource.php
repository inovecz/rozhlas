<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var $this File */
        return [
            'id' => $this->getId(),
            'type' => $this->getType(),
            'subtype' => $this->getSubtype(),
            'subtype_translated' => $this->getSubtype()->translate(),
            'author' => $this->getAuthorId(),
            'name' => $this->getName(),
            'filename' => $this->getFilename(),
            'path' => $this->getPath(),
            'extension' => $this->getExtension(),
            'mime_type' => $this->getMimeType(),
            'size' => $this->getSize(),
            'metadata' => $this->getMetadata(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
