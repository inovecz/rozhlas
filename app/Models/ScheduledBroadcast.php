<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScheduledBroadcast extends Model
{
    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'is_repeating' => 'boolean',
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: RELATIONS">
    public function intro(): HasOne
    {
        return $this->hasOne(File::class, 'id', 'intro_id');
    }

    public function recording(): HasOne
    {
        return $this->hasOne(File::class, 'id', 'recording_id');
    }

    public function outro(): HasOne
    {
        return $this->hasOne(File::class, 'id', 'outro_id');
    }
    // </editor-fold desc="Region: RELATIONS">

    // <editor-fold desc="Region: GETTERS">
    public function getTitle(): string
    {
        return $this->title;
    }

    public function getScheduledAt(): Carbon
    {
        return $this->scheduled_at;
    }

    public function isRepeating(): bool
    {
        return $this->is_repeating;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
