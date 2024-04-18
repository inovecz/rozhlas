<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'end_at' => 'datetime',
            'is_repeating' => 'boolean',
            'common_ids' => 'json',
        ];
    }
    // </editor-fold desc="Region: STATE DEFINITION">

    // <editor-fold desc="Region: BOOT">
    protected static function boot()
    {
        parent::boot();
        static::saving(static function (Schedule $schedule) {
            $duration = $schedule->calculateDuration();
            $schedule->setAttribute('duration', $duration);
            $schedule->setAttribute('end_at', $schedule->getScheduledAt()->copy()->addSeconds($duration));
        });
    }
    // </editor-fold desc="Region: BOOT">

    // <editor-fold desc="Region: RELATIONS">
    public function intro(): HasOne
    {
        return $this->hasOne(File::class, 'id', 'intro_id');
    }

    public function opening(): HasOne
    {
        return $this->hasOne(File::class, 'id', 'opening_id');
    }

    public function commons(): HasMany
    {
        return $this->hasMany(File::class, 'id', 'common_ids');
    }

    public function closing(): HasOne
    {
        return $this->hasOne(File::class, 'id', 'closing_id');
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

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function getEndAt(): ?Carbon
    {
        return $this->end_at;
    }

    public function isRepeating(): bool
    {
        return $this->is_repeating;
    }
    // </editor-fold desc="Region: GETTERS">

    // <editor-fold desc="Region: COMPUTED GETTERS">
    // </editor-fold desc="Region: COMPUTED GETTERS">

    // <editor-fold desc="Region: ARRAY GETTERS">
    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'scheduled_at' => $this->getScheduledAt(),
            'duration' => $this->getDuration(),
            'end_at' => $this->getEndAt(),
            'is_repeating' => $this->isRepeating(),
            'intro' => $this->intro?->getToArrayDefault(),
            'opening' => $this->opening?->getToArrayDefault(),
            'commons' => $this->commons->map(fn(File $file) => $file->getToArrayDefault()),
            'outro' => $this->outro?->getToArrayDefault(),
            'closing' => $this->closing?->getToArrayDefault(),
        ];
    }
    // </editor-fold desc="Region: ARRAY GETTERS">

    // <editor-fold desc="Region: FUNCTIONS">
    public function calculateDuration(): int
    {
        $duration = 0;
        $duration += $this->intro?->getMetadata()['duration'] ?? 0;
        $duration += $this->opening?->getMetadata()['duration'] ?? 0;
        $duration += $this->commons->sum(function (File $file) {
            return $this->isRepeating() ? $file->getMetadata()['duration'] * 2 : $file->getMetadata()['duration'];
        });
        $duration += $this->outro?->getMetadata()['duration'] ?? 0;
        $duration += $this->closing?->getMetadata()['duration'] ?? 0;
        return $duration;
    }
    // </editor-fold desc="Region: FUNCTIONS">

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
