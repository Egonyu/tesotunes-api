<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObservabilityEntryPoint extends Model
{
    protected $fillable = [
        'entry_key',
        'label',
        'subsystem',
        'route_pattern',
        'methods',
        'exposure_type',
        'criticality',
        'total_hits',
        'unique_sources',
        'blocked_hits',
        'failed_hits',
        'successful_hits',
        'suspicious_hits',
        'risk_score',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'methods' => 'array',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
