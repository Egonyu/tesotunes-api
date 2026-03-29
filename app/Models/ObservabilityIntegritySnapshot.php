<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObservabilityIntegritySnapshot extends Model
{
    protected $fillable = [
        'snapshot_key',
        'path',
        'category',
        'hash',
        'status',
        'host',
        'metadata',
        'observed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'observed_at' => 'datetime',
    ];
}
