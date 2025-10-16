<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JsvvEvent extends Model
{
    protected $fillable = [
        'command',
        'mid',
        'priority',
        'duplicate',
        'payload',
    ];

    protected $casts = [
        'duplicate' => 'boolean',
        'payload' => 'array',
    ];
}
