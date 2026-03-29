<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObservabilityRollupHourly extends Model
{
    protected $table = 'observability_rollups_hourly';

    protected $fillable = [
        'bucket_start',
        'dimension_type',
        'dimension_key',
        'total_events',
        'blocked_events',
        'failed_events',
        'suspicious_events',
        'successful_suspicious_events',
        'distinct_sources',
        'avg_risk_score',
        'metadata',
    ];

    protected $casts = [
        'bucket_start' => 'datetime',
        'metadata' => 'array',
    ];
}
