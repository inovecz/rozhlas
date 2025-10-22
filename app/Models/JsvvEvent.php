<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JsvvEvent extends Model
{
    protected $fillable = [
        'message_id',
        'event',
        'data',
        'command',
        'mid',
        'priority',
        'duplicate',
        'payload',
    ];

    protected $casts = [
        'data' => 'array',
        'duplicate' => 'boolean',
        'payload' => 'array',
    ];

    public function message()
    {
        return $this->belongsTo(JsvvMessage::class, 'message_id');
    }
}
