<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastPlaylistItem extends Model
{
    protected $fillable = [
        'playlist_id',
        'position',
        'recording_id',
        'duration_seconds',
        'gain',
        'gap_ms',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(BroadcastPlaylist::class, 'playlist_id');
    }
}
