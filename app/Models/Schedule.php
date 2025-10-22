<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;
use Staudenmeir\EloquentJsonRelations\Relations\BelongsToJson;

class Schedule extends Model
{
    use SoftDeletes, HasJsonRelationships;

    // <editor-fold desc="Region: STATE DEFINITION">
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'end_at' => 'datetime',
            'processed_at' => 'datetime',
            'is_repeating' => 'boolean',
            'common_ids' => 'json',
            'repeat_interval_meta' => 'array',
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

    public function commons(): BelongsToJson
    {
        return $this->belongsToJson(File::class, 'common_ids');
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

    public function getProcessedAt(): ?Carbon
    {
        return $this->processed_at;
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
            'processed_at' => $this->getProcessedAt(),
            'is_repeating' => $this->isRepeating(),
            'repeat_count' => $this->repeat_count,
            'repeat_interval_value' => $this->repeat_interval_value,
            'repeat_interval_unit' => $this->repeat_interval_unit,
            'repeat_interval_meta' => $this->repeat_interval_meta,
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
        $baseDuration = 0;
        $baseDuration += $this->intro?->getMetadata()['duration'] ?? 0;
        $baseDuration += $this->opening?->getMetadata()['duration'] ?? 0;
        $baseDuration += $this->commons->sum(static function (File $file) {
            return $file->getMetadata()['duration'] ?? 0;
        });
        $baseDuration += $this->outro?->getMetadata()['duration'] ?? 0;
        $baseDuration += $this->closing?->getMetadata()['duration'] ?? 0;

        if (!$this->isRepeating()) {
            return $baseDuration;
        }

        $repeatCount = max(1, (int) ($this->repeat_count ?? 1));
        $intervalSeconds = max(0, $this->resolveIntervalSeconds());

        return ($baseDuration * $repeatCount) + $intervalSeconds * max(0, $repeatCount - 1);
    }
    // </editor-fold desc="Region: FUNCTIONS">

    private function resolveIntervalSeconds(): int
    {
        $value = (int) ($this->repeat_interval_value ?? 0);
        $unit = $this->repeat_interval_unit ?? null;

        return match ($unit) {
            'minutes' => $value * 60,
            'hours' => $value * 3600,
            'days' => $value * 86400,
            'months' => $value * 30 * 86400,
            'years' => $value * 365 * 86400,
            default => 0,
        };
    }

    // <editor-fold desc="Region: SCOPES">
    // </editor-fold desc="Region: SCOPES">
}
