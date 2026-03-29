<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ObservabilityEntity extends Model
{
    protected $fillable = [
        'entity_key',
        'entity_type',
        'label',
        'risk_score',
        'first_seen_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            ObservabilityEvent::class,
            'observability_event_entities',
            'entity_id',
            'event_id'
        )->withPivot('relation')->withTimestamps();
    }
}
