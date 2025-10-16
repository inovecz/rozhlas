<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BroadcastPlaylist extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $table = 'broadcast_playlists';

    protected $fillable = [
        'id',
        'status',
        'route',
        'zones',
        'options',
        'created_at',
        'updated_at',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'route' => 'array',
        'zones' => 'array',
        'options' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if (!$model->getKey()) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
            $model->setAttribute('created_at', now());
            $model->setAttribute('updated_at', now());
        });

        static::updating(static function (self $model): void {
            $model->setAttribute('updated_at', now());
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(BroadcastPlaylistItem::class, 'playlist_id');
    }
}
