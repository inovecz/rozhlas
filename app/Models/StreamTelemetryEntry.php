<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamTelemetryEntry extends Model
{
    protected $fillable = [
        'type',
        'session_id',
        'playlist_id',
        'payload',
        'recorded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];
}
