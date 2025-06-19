<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JsvvAudioTypeEnum;
use App\Enums\JsvvAudioGroupEnum;
use App\Enums\JsvvAudioSourceEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JsvvAudio extends Model
{
    // <editor-fold desc="Region: STATE DEFINITION">
    protected $primaryKey = 'symbol';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'type' => JsvvAudioTypeEnum::class,
            'group' => JsvvAudioGroupEnum::class,
            'source' => JsvvAudioSourceEnum::class,
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getId(): string
    {
        return $this->getSymbol();
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): JsvvAudioTypeEnum
    {
        return $this->type;
    }

    public function getGroup(): JsvvAudioGroupEnum
    {
        return $this->group;
    }

    public function getFileId(): ?int
    {
        return $this->file_id;
    }

    public function getSource(): ?JsvvAudioSourceEnum
    {
        return $this->source;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        $array = [
            'symbol' => $this->getSymbol(),
            'name' => $this->getName(),
            'type' => $this->getType()->value,
            'group' => $this->getGroup()->value,
            'group_label' => $this->getGroup()->translate(),
            'source' => $this->getSource()?->value,
            'file_id' => $this->getFileId(),
        ];

        if ($this->relationLoaded('file')) {
            $array['file'] = $this->file?->getToArray();
        }
        return $array;
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
