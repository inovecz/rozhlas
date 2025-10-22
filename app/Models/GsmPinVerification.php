<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GsmPinVerification extends Model
{
    protected $fillable = [
        'session_id',
        'pin',
        'verified',
        'verified_at',
        'attempts',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(GsmCallSession::class, 'session_id');
    }
}
