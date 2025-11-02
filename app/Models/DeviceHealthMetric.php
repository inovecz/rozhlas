<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceHealthMetric extends Model
{
    protected $table = 'device_health_metrics';

    protected $primaryKey = 'metric';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'metric',
        'state',
        'meta',
        'last_fault_notified_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_fault_notified_at' => 'datetime',
    ];
}
