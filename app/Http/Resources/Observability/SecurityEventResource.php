<?php

namespace App\Http\Resources\Observability;

use App\Models\ObservabilityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ObservabilityEvent
 */
class SecurityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'domain' => $this->domain,
            'category' => $this->category,
            'outcome' => $this->outcome,
            'severity' => $this->severity,
            'title' => $this->title,
            'summary' => $this->summary,
            'risk' => [
                'score' => (int) $this->risk_score,
                'reasons' => $this->risk_reasons ?? [],
            ],
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'label' => $this->actor_label,
            ],
            'source' => [
                'ip' => $this->source_ip,
                'country' => $this->source_country,
                'asn' => $this->source_asn,
                'user_agent' => $this->source_user_agent,
            ],
            'target' => [
                'route' => $this->target_route,
                'method' => $this->target_method,
                'resource_type' => $this->target_resource_type,
                'resource_id' => $this->target_resource_id,
            ],
            'attack' => [
                'technique' => $this->attack_technique,
                'pattern' => $this->attack_pattern,
            ],
            'correlation' => [
                'request_id' => $this->request_id,
                'trace_id' => $this->trace_id,
                'session_id' => $this->session_id,
                'incident_key' => $this->incident_key,
            ],
            'host' => $this->host,
            'environment' => $this->environment,
            'event_type' => $this->details['event_type'] ?? null,
            'details' => $this->details ?? [],
        ];
    }
}
