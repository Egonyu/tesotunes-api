<?php

namespace App\Services\Observability;

use App\Jobs\Observability\RecordSecurityEvent;
use App\Models\ObservabilityEntity;
use App\Models\ObservabilityEvent;
use App\Services\Observability\Risk\SecurityRiskScorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single write path for security telemetry.
 *
 * The platform emits a {@see SecurityEvent} at each touchpoint; this recorder
 * normalizes it, scores its risk, persists it to `observability_events`, and
 * links the related entities (IP, actor, session, payment reference).
 *
 * Touchpoints should call {@see self::emit()} (queued, non-blocking). Use
 * {@see self::record()} directly only from already-async code or tests.
 */
class SecurityEventRecorder
{
    public function __construct(
        private readonly SecurityRiskScorer $scorer,
    ) {}

    /**
     * Queue a security event for recording. Never throws — telemetry must not
     * break the request that produced it.
     */
    public static function emit(SecurityEvent $event): void
    {
        try {
            RecordSecurityEvent::dispatch($event->toArray());
        } catch (Throwable $e) {
            Log::warning('observability.emit_failed', [
                'type' => $event->type->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function record(SecurityEvent $event): ObservabilityEvent
    {
        $type = $event->type;
        $severity = $event->resolvedSeverity();
        $outcome = $event->resolvedOutcome();
        $risk = $this->scorer->score($event);

        $payload = [
            'source_type' => $event->rawRef['source'] ?? 'app',
            'source_id' => $event->rawRef['id'] ?? null,
            'occurred_at' => $event->occurredAt,
            'domain' => $type->domain()->value,
            'category' => $type->category(),
            'outcome' => $outcome->value,
            'severity' => $severity->value,
            'title' => $event->resolvedTitle(),
            'summary' => $event->summary,
            'source_ip' => $event->sourceIp,
            'source_country' => $event->sourceCountry,
            'source_asn' => $event->sourceAsn,
            'source_user_agent' => $event->sourceUserAgent,
            'actor_type' => $event->actorType,
            'actor_id' => $event->actorId,
            'actor_label' => $event->actorLabel,
            'target_route' => $event->targetRoute,
            'target_method' => $event->targetMethod,
            'target_resource_type' => $event->targetResourceType,
            'target_resource_id' => $event->targetResourceId,
            'attack_technique' => $event->attackTechnique,
            'attack_pattern' => $event->attackPattern,
            'host' => $event->host ?? $this->defaultHost(),
            'environment' => $event->environment ?? (string) config('app.env'),
            'request_id' => $event->requestId,
            'trace_id' => $event->traceId,
            'session_id' => $event->sessionId,
            'incident_key' => $event->incidentKey,
            'risk_score' => $risk['score'],
            'risk_reasons' => $risk['reasons'],
            'details' => array_merge($event->details, ['event_type' => $type->value]),
            'raw_ref' => $event->rawRef ?: ['source' => 'app'],
            'linked_entity_keys' => [],
        ];

        $observabilityEvent = ObservabilityEvent::query()->updateOrCreate(
            ['event_key' => $event->eventKey ?? $type->value.':'.Str::ulid()],
            $payload,
        );

        $this->linkEntities($observabilityEvent);

        return $observabilityEvent;
    }

    private function linkEntities(ObservabilityEvent $event): void
    {
        $candidates = [];

        if ($event->source_ip) {
            $candidates[] = [
                'entity_key' => 'ip:'.$event->source_ip,
                'entity_type' => 'ip',
                'label' => $event->source_ip,
                'relation' => 'source',
                'metadata' => array_filter([
                    'country' => $event->source_country,
                    'asn' => $event->source_asn,
                ]),
            ];
        }

        if ($event->actor_id && $event->actor_type) {
            $candidates[] = [
                'entity_key' => $event->actor_type.':'.$event->actor_id,
                'entity_type' => $event->actor_type,
                'label' => $event->actor_label ?? (string) $event->actor_id,
                'relation' => 'actor',
                'metadata' => [],
            ];
        }

        if ($event->session_id) {
            $candidates[] = [
                'entity_key' => 'session:'.$event->session_id,
                'entity_type' => 'session',
                'label' => $event->session_id,
                'relation' => 'session',
                'metadata' => [],
            ];
        }

        $paymentReference = $event->details['payment_reference'] ?? null;
        if (is_string($paymentReference) && $paymentReference !== '') {
            $candidates[] = [
                'entity_key' => 'payment_reference:'.$paymentReference,
                'entity_type' => 'payment_reference',
                'label' => $paymentReference,
                'relation' => 'payment',
                'metadata' => [],
            ];
        }

        $linkedKeys = [];

        foreach ($candidates as $candidate) {
            $existing = ObservabilityEntity::query()
                ->where('entity_key', $candidate['entity_key'])
                ->first();

            $entity = ObservabilityEntity::query()->updateOrCreate(
                ['entity_key' => $candidate['entity_key']],
                [
                    'entity_type' => $candidate['entity_type'],
                    'label' => $candidate['label'],
                    'risk_score' => max((int) $event->risk_score, (int) ($existing?->risk_score ?? 0)),
                    'first_seen_at' => $existing?->first_seen_at ?? $event->occurred_at,
                    'last_seen_at' => $event->occurred_at,
                    'metadata' => $candidate['metadata'],
                ],
            );

            DB::table('observability_event_entities')->upsert(
                [[
                    'event_id' => $event->id,
                    'entity_id' => $entity->id,
                    'relation' => $candidate['relation'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['event_id', 'entity_id', 'relation'],
                ['updated_at'],
            );

            $linkedKeys[] = $entity->entity_key;
        }

        if ($linkedKeys !== []) {
            $event->forceFill(['linked_entity_keys' => $linkedKeys])->save();
        }
    }

    private function defaultHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (gethostname() ?: 'unknown');
    }
}
