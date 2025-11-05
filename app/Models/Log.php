<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends Model
{
    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: BOOT">
    protected static function boot()
    {
        parent::boot();
    }
    // </editor-fold desc="Region: BOOT">

    // <editor-fold desc="Region: RELATIONS">
    public function originator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getData(): ?array
    {
        return $this->data;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'ip' => $this->getIp(),
            'data' => $this->getData(),
            'originator' => $this->originator?->getToArray(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
