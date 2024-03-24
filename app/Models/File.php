<?php

namespace App\Models;

use App\Enums\FileTypeEnum;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class File extends Model
{
    use SoftDeletes;

    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
    protected $casts = [
        'type' => FileTypeEnum::class,
        'metadata' => 'array',
    ];
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getAuthorId(): ?int
    {
        return $this->author_id;
    }

    public function getType(): FileTypeEnum
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getMimeType(): string
    {
        return $this->mime_type;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    public function getStoragePath(): string
    {
        return $this->getPath().$this->getFilename().'.'.$this->getExtension();
    }

    public function getBlob(): ?string
    {
        if (!Storage::exists($this->getStoragePath())) {
            return null;
        }
        return Storage::get($this->getStoragePath());
    }
    // </editor-fold desc="Region: COMPUTED GETTERS">
}
