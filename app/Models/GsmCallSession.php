<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GsmCallSession extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'caller',
        'status',
        'authorised',
        'metadata',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'authorised' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if (!$model->getKey()) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }

    public function pins(): HasMany
    {
        return $this->hasMany(GsmPinVerification::class, 'session_id');
    }
}
