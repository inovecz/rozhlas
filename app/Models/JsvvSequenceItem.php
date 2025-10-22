<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JsvvSequenceItem extends Model
{
    protected $fillable = [
        'sequence_id',
        'position',
        'category',
        'slot',
        'voice',
        'repeat',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(JsvvSequence::class, 'sequence_id');
    }
}
