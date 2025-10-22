<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JsvvMessage extends Model
{
    protected $fillable = [
        'network_id',
        'vyc_id',
        'kpps_address',
        'operator_id',
        'type',
        'command',
        'params',
        'priority',
        'payload_timestamp',
        'received_at',
        'raw_message',
        'status',
        'dedup_key',
        'artisan_exit_code',
        'meta',
    ];

    protected $casts = [
        'params' => 'array',
        'meta' => 'array',
        'received_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(JsvvEvent::class, 'message_id');
    }
}
