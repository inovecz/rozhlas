<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BroadcastSession extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'source',
        'route',
        'zones',
        'options',
        'status',
        'started_at',
        'stopped_at',
        'stop_reason',
        'python_response',
    ];

    protected $casts = [
        'route' => 'array',
        'zones' => 'array',
        'options' => 'array',
        'python_response' => 'array',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if (!$model->getKey()) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
