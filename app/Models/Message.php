<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageTypeEnum;
use App\Enums\MessageStateEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'type' => MessageTypeEnum::class,
            'state' => MessageStateEnum::class,
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getType(): MessageTypeEnum
    {
        return $this->type;
    }

    public function getState(): MessageStateEnum
    {
        return $this->state;
    }

    public function getContent(): string
    {
        return $this->content;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'contact' => $this->contact->getToArray(),
            'type' => $this->getType()->value,
            'state' => $this->getState()->value,
            'content' => $this->getContent(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
