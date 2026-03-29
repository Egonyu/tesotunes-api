<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ObservabilityEvent extends Model
{
    protected $fillable = [
        'event_key',
        'source_type',
        'source_id',
        'occurred_at',
        'domain',
        'category',
        'outcome',
        'severity',
        'title',
        'summary',
        'source_ip',
        'source_country',
        'source_asn',
        'source_user_agent',
        'actor_type',
        'actor_id',
        'actor_label',
        'target_route',
        'target_method',
        'target_resource_type',
        'target_resource_id',
        'attack_technique',
        'attack_pattern',
        'host',
        'environment',
        'request_id',
        'trace_id',
        'session_id',
        'incident_key',
        'risk_score',
        'risk_reasons',
        'details',
        'raw_ref',
        'linked_entity_keys',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'risk_reasons' => 'array',
        'details' => 'array',
        'raw_ref' => 'array',
        'linked_entity_keys' => 'array',
    ];

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(
            ObservabilityEntity::class,
            'observability_event_entities',
            'event_id',
            'entity_id'
        )->withPivot('relation')->withTimestamps();
    }
}
