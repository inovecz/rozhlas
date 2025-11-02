<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recording extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'source',
        'started_at',
        'ended_at',
        'duration_s',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getToArrayDefault(): array
    {
        return [
            'id' => $this->getId(),
            'path' => $this->path,
            'source' => $this->source,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'duration_s' => $this->duration_s,
            'created_at' => $this->created_at,
        ];
    }
}
