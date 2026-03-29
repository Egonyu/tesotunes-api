<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ObservabilityIncident extends Model
{
    protected $fillable = [
        'incident_key',
        'title',
        'status',
        'severity',
        'owner_id',
        'summary',
        'notes',
        'detected_at',
        'started_at',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            ObservabilityEvent::class,
            'observability_incident_events',
            'incident_id',
            'event_id'
        )->withTimestamps();
    }
}
