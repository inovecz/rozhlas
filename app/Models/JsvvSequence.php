<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class JsvvSequence extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'items',
        'options',
        'priority',
        'status',
        'created_at',
        'triggered_at',
        'completed_at',
    ];

    protected $casts = [
        'items' => 'array',
        'options' => 'array',
        'created_at' => 'datetime',
        'triggered_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if (!$model->getKey()) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
            $model->setAttribute('created_at', now());
        });
    }

    public function sequenceItems(): HasMany
    {
        return $this->hasMany(JsvvSequenceItem::class, 'sequence_id');
    }
}
