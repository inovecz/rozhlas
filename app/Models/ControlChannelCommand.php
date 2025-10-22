<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlChannelCommand extends Model
{
    protected $fillable = [
        'command',
        'state_before',
        'state_after',
        'reason',
        'message_id',
        'result',
        'payload',
        'issued_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'issued_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(JsvvMessage::class, 'message_id');
    }
}
