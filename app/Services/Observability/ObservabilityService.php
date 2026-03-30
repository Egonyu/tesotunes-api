<?php

namespace App\Services\Observability;

use App\Models\ApiUsageLog;
use App\Models\AuditLog;
use App\Models\ObservabilityEntity;
use App\Models\ObservabilityEntryPoint;
use App\Models\ObservabilityEvent;
use App\Models\ObservabilityIncident;
use App\Models\ObservabilityIntegritySnapshot;
use App\Models\ObservabilityRollupHourly;
use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Models\User;
use App\Services\PerformanceMonitoringService;
use App\Services\Payment\PaymentObservabilityService;
use App\Services\QueryOptimizationService;
use App\Services\SystemMonitoringService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ObservabilityService
{
    public function __construct(
        protected ObservabilityRiskService $risk,
        protected ObservabilityCatalogService $catalog,
        protected PaymentObservabilityService $payments,
        protected PerformanceMonitoringService $performanceMonitoring,
        protected QueryOptimizationService $queryOptimization,
        protected SystemMonitoringService $systemMonitoring,
    ) {}

    public function syncPhaseOneData(): void
    {
        $this->syncAuditEvents();
        $this->syncApiUsageEvents();
        $this->syncPaymentIssueEvents();
        $this->syncSystemSignals();
        $this->syncDatabaseSignals();
        $this->syncIntegritySnapshots();
        $this->syncEntryPoints();
    }

    public function ingestCollectorBatch(array $events): int
    {
        collect($events)->values()->each(function (array $event, int $index) {
            $eventKey = $event['event_key']
                ?? 'collector:'.md5(json_encode([
                    'occurred_at' => $event['occurred_at'] ?? null,
                    'domain' => $event['domain'] ?? null,
                    'category' => $event['category'] ?? null,
                    'title' => $event['title'] ?? null,
                    'index' => $index,
                ]));

            $source = (array) ($event['source'] ?? []);
            $actor = (array) ($event['actor'] ?? []);
            $target = (array) ($event['target'] ?? []);
            $attack = (array) ($event['attack'] ?? []);
            $infra = (array) ($event['infra'] ?? []);
            $correlation = (array) ($event['correlation'] ?? []);
            $inferredPattern = $this->inferCollectorAttackPattern($event, $attack);

            $this->upsertEvent([
                'event_key' => $eventKey,
                'source_type' => 'collector',
                'source_id' => (string) ($event['event_key'] ?? $eventKey),
                'occurred_at' => Carbon::parse($event['occurred_at']),
                'domain' => $event['domain'],
                'category' => $event['category'],
                'outcome' => $event['outcome'],
                'severity' => $event['severity'],
                'title' => $event['title'],
                'summary' => $event['summary'],
                'source_ip' => $source['ip'] ?? null,
                'source_country' => $source['country'] ?? null,
                'source_asn' => $source['asn'] ?? null,
                'source_user_agent' => $source['user_agent'] ?? null,
                'actor_type' => $actor['type'] ?? null,
                'actor_id' => isset($actor['id']) ? (string) $actor['id'] : null,
                'actor_label' => $actor['label'] ?? null,
                'target_route' => $target['route'] ?? null,
                'target_method' => $target['method'] ?? null,
                'target_resource_type' => $target['resource_type'] ?? null,
                'target_resource_id' => isset($target['resource_id']) ? (string) $target['resource_id'] : null,
                'attack_technique' => $attack['technique'] ?? null,
                'attack_pattern' => $attack['pattern'] ?? $inferredPattern,
                'host' => $infra['host'] ?? null,
                'environment' => $infra['environment'] ?? null,
                'request_id' => $correlation['request_id'] ?? null,
                'trace_id' => $correlation['trace_id'] ?? null,
                'session_id' => $correlation['session_id'] ?? null,
                'incident_key' => $correlation['incident_id'] ?? null,
                'details' => array_merge($event['details'] ?? [], [
                    'signal_type' => $event['details']['signal_type'] ?? $inferredPattern,
                    'collector_stream' => $event['raw_ref']['stream'] ?? null,
                ]),
                'raw_ref' => $event['raw_ref'] ?? ['source' => 'collector'],
                'linked_entity_keys' => [],
            ]);
        });

        $this->syncEntryPoints();

        return count($events);
    }

    public function overview(array $filters): array
    {
        $query = $this->eventsQuery($filters);
        $collectorSummary = $this->collectorSummary($filters);
        $dbSummary = $this->databaseCollectorSummary($filters);

        return [
            'summary' => [
                'active_threats' => (clone $query)->whereIn('severity', ['high', 'critical'])->count(),
                'suspicious_successes' => (clone $query)->where('outcome', 'success')->where('risk_score', '>=', 65)->count(),
                'bot_pressure' => (clone $query)->where('domain', 'bot')->count(),
                'payment_risk_events' => (clone $query)->where('domain', 'payments')->where('risk_score', '>=', 60)->count(),
                'db_anomalies' => (clone $query)->where('domain', 'db')->count(),
                'unresolved_incidents' => ObservabilityIncident::query()->whereNotIn('status', ['resolved', 'closed'])->count(),
                'collector_stale_sources' => $collectorSummary['summary']['stale_sources'] ?? 0,
                'collector_reporting_hosts' => $collectorSummary['summary']['hosts'] ?? 0,
                'collector_telemetry_gaps' => $collectorSummary['summary']['telemetry_gaps'] ?? 0,
                'critical_system_signals' => $collectorSummary['summary']['critical_system_signals'] ?? 0,
                'db_auth_failures' => $dbSummary['summary']['auth_failures'] ?? 0,
                'db_privileged_writes' => $dbSummary['summary']['privileged_writes'] ?? 0,
                'db_destructive_queries' => $dbSummary['summary']['destructive_queries'] ?? 0,
            ],
            'top_attacked_endpoints' => (clone $query)
                ->select('target_route', DB::raw('COUNT(*) as total'))
                ->whereNotNull('target_route')
                ->groupBy('target_route')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['route' => $row->target_route, 'total' => (int) $row->total])
                ->values()
                ->all(),
            'recent_events' => $this->serializeEventCollection((clone $query)->latest('occurred_at')->limit(12)->get()),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function events(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->eventsQuery($filters)->orderByDesc('occurred_at')->paginate($perPage);
    }

    public function eventDetail(string $id): array
    {
        $event = ObservabilityEvent::query()->with('entities')->findOrFail($id);

        $related = ObservabilityEvent::query()
            ->where('id', '!=', $event->id)
            ->where(function (Builder $query) use ($event) {
                $query->when($event->source_ip, fn (Builder $sub) => $sub->orWhere('source_ip', $event->source_ip))
                    ->when($event->request_id, fn (Builder $sub) => $sub->orWhere('request_id', $event->request_id))
                    ->when($event->trace_id, fn (Builder $sub) => $sub->orWhere('trace_id', $event->trace_id))
                    ->when($event->incident_key, fn (Builder $sub) => $sub->orWhere('incident_key', $event->incident_key));
            })
            ->latest('occurred_at')
            ->limit(12)
            ->get();

        return [
            'event' => $this->serializeEvent($event),
            'related_events' => $this->serializeEventCollection($related),
            'entities' => $event->entities->map(fn (ObservabilityEntity $entity) => $this->serializeEntity($entity))->values()->all(),
            'raw' => $event->raw_ref,
            'timeline' => $this->serializeEventCollection($related->prepend($event)->sortByDesc('occurred_at')->values()),
            'pivot_targets' => [
                'attacker' => $event->source_ip,
                'session_id' => $event->session_id,
                'payment_reference' => $event->details['payment_reference'] ?? null,
                'incident_id' => $event->incident_key,
            ],
        ];
    }

    public function entryPoints(array $filters): array
    {
        return ObservabilityEntryPoint::query()
            ->orderByDesc('risk_score')
            ->orderByDesc('total_hits')
            ->get()
            ->map(function (ObservabilityEntryPoint $entry) {
                return [
                    'entry_key' => $entry->entry_key,
                    'label' => $entry->label,
                    'subsystem' => $entry->subsystem,
                    'route_pattern' => $entry->route_pattern,
                    'methods' => $entry->methods ?? [],
                    'exposure_type' => $entry->exposure_type,
                    'criticality' => $entry->criticality,
                    'totals' => [
                        'hits' => (int) $entry->total_hits,
                        'unique_sources' => (int) $entry->unique_sources,
                        'blocked' => (int) $entry->blocked_hits,
                        'failed' => (int) $entry->failed_hits,
                        'success' => (int) $entry->successful_hits,
                        'suspicious' => (int) $entry->suspicious_hits,
                    ],
                    'risk_score' => (int) $entry->risk_score,
                    'last_seen_at' => $entry->last_seen_at?->toIso8601String(),
                    'metadata' => $entry->metadata ?? [],
                ];
            })
            ->values()
            ->all();
    }

    public function attackers(array $filters): array
    {
        return ObservabilityEntity::query()
            ->where('entity_type', 'ip')
            ->orderByDesc('risk_score')
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get()
            ->map(fn (ObservabilityEntity $entity) => $this->serializeAttacker($entity))
            ->values()
            ->all();
    }

    public function attackerDetail(string $id): array
    {
        $entity = ObservabilityEntity::query()
            ->where('entity_type', 'ip')
            ->where(fn (Builder $query) => $query->where('id', $id)->orWhere('entity_key', $id))
            ->firstOrFail();

        return [
            'attacker' => $this->serializeAttacker($entity),
            'events' => $this->serializeEventCollection(
                $entity->events()->latest('occurred_at')->limit(25)->get()
            ),
        ];
    }

    public function bots(array $filters): array
    {
        $query = $this->eventsQuery(array_merge($filters, ['domain' => ['bot']]));

        return [
            'summary' => [
                'events' => (clone $query)->count(),
                'blocked' => (clone $query)->where('outcome', 'blocked')->count(),
                'successful' => (clone $query)->where('outcome', 'success')->count(),
                'top_404_scanners' => (clone $query)->where('attack_pattern', '404_scan')->count(),
            ],
            'top_bots' => (clone $query)
                ->select('source_ip', DB::raw('COUNT(*) as total'), DB::raw('MAX(risk_score) as risk_score'))
                ->whereNotNull('source_ip')
                ->groupBy('source_ip')
                ->orderByDesc('total')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'ip' => $row->source_ip,
                    'events' => (int) $row->total,
                    'risk_score' => (int) $row->risk_score,
                ])
                ->values()
                ->all(),
        ];
    }

    public function authSessions(array $filters): array
    {
        $query = $this->eventsQuery(array_merge($filters, ['domain' => ['auth']]));

        return [
            'summary' => [
                'failed_logins' => (clone $query)->where('outcome', 'failed')->count(),
                'successful_logins' => (clone $query)->where('outcome', 'success')->count(),
                'suspicious_successes' => (clone $query)->where('outcome', 'success')->where('risk_score', '>=', 65)->count(),
            ],
            'recent' => $this->serializeEventCollection((clone $query)->latest('occurred_at')->limit(20)->get()),
        ];
    }

    public function authSessionDetail(string $sessionId): array
    {
        $events = ObservabilityEvent::query()
            ->where('session_id', $sessionId)
            ->latest('occurred_at')
            ->limit(25)
            ->get();

        return [
            'session' => [
                'session_id' => $sessionId,
                'event_count' => $events->count(),
                'max_risk_score' => (int) ($events->max('risk_score') ?? 0),
                'first_seen_at' => $events->last()?->occurred_at?->toIso8601String(),
                'last_seen_at' => $events->first()?->occurred_at?->toIso8601String(),
                'source_ips' => $events->pluck('source_ip')->filter()->unique()->values()->all(),
                'actors' => $events->pluck('actor_label')->filter()->unique()->values()->all(),
                'outcomes' => $events->pluck('outcome')->filter()->countBy()->all(),
            ],
            'events' => $this->serializeEventCollection($events),
        ];
    }

    public function paymentReferenceDetail(string $reference): array
    {
        $payment = Payment::query()
            ->where('payment_reference', $reference)
            ->orWhere('transaction_reference', $reference)
            ->orWhere('provider_transaction_id', $reference)
            ->latest('created_at')
            ->first();

        $paymentDetail = $payment ? $this->payments->paymentDetail($payment) : null;

        $events = ObservabilityEvent::query()
            ->where('details->payment_reference', $reference)
            ->latest('occurred_at')
            ->limit(25)
            ->get();

        return [
            'payment_reference' => $reference,
            'payment' => $paymentDetail['payment'] ?? null,
            'issues' => $paymentDetail['issues'] ?? [],
            'timeline' => $paymentDetail['timeline'] ?? [],
            'summary' => [
                'event_count' => $events->count(),
                'max_risk_score' => (int) ($events->max('risk_score') ?? 0),
                'source_ips' => $events->pluck('source_ip')->filter()->unique()->values()->all(),
                'outcomes' => $events->groupBy('outcome')->map->count()->all(),
                'last_seen_at' => optional($events->first()?->occurred_at)->toIso8601String(),
            ],
            'events' => $this->serializeEventCollection($events),
        ];
    }

    public function paymentsRisk(array $filters): array
    {
        return [
            'dashboard' => $this->payments->buildDashboard([]),
            'high_risk_events' => $this->serializeEventCollection(
                $this->eventsQuery(array_merge($filters, ['domain' => ['payments']]))
                    ->where('risk_score', '>=', 60)
                    ->latest('occurred_at')
                    ->limit(20)
                    ->get()
            ),
        ];
    }

    public function integrations(array $filters): array
    {
        $query = $this->eventsQuery(array_merge($filters, [
            'domain' => ['payments'],
            'category' => ['webhook'],
        ]));

        $events = (clone $query)->latest('occurred_at')->limit(50)->get();

        $byProvider = $events
            ->groupBy(fn (ObservabilityEvent $event) => (string) ($event->details['provider'] ?? 'unknown'))
            ->map(function (Collection $providerEvents, string $provider) {
                return [
                    'provider' => $provider,
                    'total_events' => $providerEvents->count(),
                    'signature_failures' => $providerEvents->filter(fn (ObservabilityEvent $event) => $event->attack_pattern === 'invalid_signature')->count(),
                    'replays' => $providerEvents->filter(fn (ObservabilityEvent $event) => $event->attack_pattern === 'replay')->count(),
                    'missing_references' => $providerEvents->filter(fn (ObservabilityEvent $event) => str_contains((string) $event->title, 'Missing Reference'))->count(),
                    'payment_not_found' => $providerEvents->filter(fn (ObservabilityEvent $event) => $event->attack_pattern === 'payment_not_found')->count(),
                    'successful_callbacks' => $providerEvents->filter(fn (ObservabilityEvent $event) => $event->outcome === 'success')->count(),
                    'max_risk_score' => (int) ($providerEvents->max('risk_score') ?? 0),
                    'last_seen_at' => optional($providerEvents->max('occurred_at'))->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $byPattern = $events
            ->groupBy(fn (ObservabilityEvent $event) => (string) ($event->attack_pattern ?: 'callback'))
            ->map(fn (Collection $patternEvents, string $pattern) => [
                'pattern' => $pattern,
                'count' => $patternEvents->count(),
                'max_risk_score' => (int) ($patternEvents->max('risk_score') ?? 0),
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'webhook_events' => $events->count(),
                'providers' => count($byProvider),
                'signature_failures' => collect($byProvider)->sum('signature_failures'),
                'replays' => collect($byProvider)->sum('replays'),
            ],
            'providers' => $byProvider,
            'patterns' => $byPattern,
            'recent' => $this->serializeEventCollection($events),
        ];
    }

    public function systemHost(array $filters): array
    {
        $collector = $this->collectorSummary($filters);

        return [
            'health' => $this->systemMonitoring->getSystemHealth(),
            'tests' => $this->systemMonitoring->runHealthTests(),
            'deployment' => $this->systemMonitoring->getDeploymentInfo(),
            'collector' => $collector,
            'rollups' => [
                'domains' => ObservabilityRollupHourly::query()
                    ->where('dimension_type', 'domain')
                    ->latest('bucket_start')
                    ->limit(12)
                    ->get()
                    ->map(fn (ObservabilityRollupHourly $row) => [
                        'bucket_start' => $row->bucket_start?->toIso8601String(),
                        'dimension_key' => $row->dimension_key,
                        'total_events' => (int) $row->total_events,
                        'suspicious_events' => (int) $row->suspicious_events,
                        'avg_risk_score' => (int) $row->avg_risk_score,
                    ])
                    ->values()
                    ->all(),
            ],
            'changes' => $this->serializeEventCollection(
                $this->eventsQuery(array_merge($filters, ['domain' => ['system']]))
                    ->latest('occurred_at')
                    ->limit(20)
                    ->get()
            ),
        ];
    }

    public function database(array $filters): array
    {
        $slowQueries = Cache::get('performance:slow_queries:'.now()->format('Y-m-d'), []);
        $collectorSummary = $this->databaseCollectorSummary($filters);

        return [
            'summary' => [
                'events' => $this->eventsQuery(array_merge($filters, ['domain' => ['db']]))->count(),
                ...$collectorSummary['summary'],
            ],
            'stats' => $this->queryOptimization->getDatabaseStats(),
            'slow_queries' => collect($slowQueries)->take(-10)->values()->all(),
            'collector_breakdown' => $collectorSummary['breakdown'],
            'collector_recent' => $collectorSummary['recent'],
            'recent' => $this->serializeEventCollection(
                $this->eventsQuery(array_merge($filters, ['domain' => ['db']]))
                    ->latest('occurred_at')
                    ->limit(20)
                    ->get()
            ),
        ];
    }

    public function auditTrail(array $filters): array
    {
        return [
            'recent' => $this->serializeEventCollection(
                $this->eventsQuery(array_merge($filters, ['domain' => ['audit', 'admin']]))
                    ->latest('occurred_at')
                    ->limit(30)
                    ->get()
            ),
        ];
    }

    public function changes(array $filters): array
    {
        return [
            'recent' => $this->serializeEventCollection(
                $this->eventsQuery(array_merge($filters, ['category' => ['change', 'config']]))
                    ->latest('occurred_at')
                    ->limit(20)
                    ->get()
            ),
            'integrity_snapshots' => ObservabilityIntegritySnapshot::query()
                ->latest('observed_at')
                ->limit(12)
                ->get()
                ->map(fn (ObservabilityIntegritySnapshot $snapshot) => [
                    'path' => $snapshot->path,
                    'category' => $snapshot->category,
                    'status' => $snapshot->status,
                    'observed_at' => $snapshot->observed_at?->toIso8601String(),
                    'metadata' => $snapshot->metadata ?? [],
                ])
                ->values()
                ->all(),
        ];
    }

    public function stakeholderRisk(array $filters): array
    {
        $query = $this->eventsQuery($filters)
            ->whereNotNull('actor_id')
            ->whereIn('actor_type', ['admin', 'user']);

        $topActors = (clone $query)
            ->select('actor_id', 'actor_type', 'actor_label', DB::raw('COUNT(*) as total_events'), DB::raw('MAX(risk_score) as max_risk'))
            ->groupBy('actor_id', 'actor_type', 'actor_label')
            ->orderByDesc('max_risk')
            ->orderByDesc('total_events')
            ->limit(20)
            ->get();

        return [
            'summary' => [
                'high_risk_stakeholders' => $topActors->where('max_risk', '>=', 70)->count(),
                'admin_actors' => $topActors->where('actor_type', 'admin')->count(),
                'payment_touches' => (clone $query)->where('domain', 'payments')->count(),
            ],
            'actors' => $topActors->map(function ($row) {
                $user = User::query()->find($row->actor_id);
                $events = ObservabilityEvent::query()
                    ->where('actor_id', (string) $row->actor_id)
                    ->where('actor_type', $row->actor_type);

                return [
                    'actor_id' => (string) $row->actor_id,
                    'actor_type' => $row->actor_type,
                    'label' => $row->actor_label,
                    'email' => $user?->email,
                    'last_login_at' => $user?->last_login_at?->toIso8601String(),
                    'last_admin_login_at' => $user?->last_admin_login_at?->toIso8601String(),
                    'risk_score' => (int) $row->max_risk,
                    'total_events' => (int) $row->total_events,
                    'payment_events' => (clone $events)->where('domain', 'payments')->count(),
                    'admin_events' => (clone $events)->where('domain', 'admin')->count(),
                    'successful_suspicious_events' => (clone $events)->where('outcome', 'success')->where('risk_score', '>=', 65)->count(),
                ];
            })->values()->all(),
        ];
    }

    public function stakeholderDetail(string $actorType, string $actorId): array
    {
        $events = ObservabilityEvent::query()
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId)
            ->latest('occurred_at')
            ->limit(25)
            ->get();

        $user = User::query()->find($actorId);
        $firstEvent = $events->first();

        return [
            'actor' => [
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'label' => $firstEvent?->actor_label ?? $user?->name ?? $user?->email ?? $actorId,
                'email' => $user?->email,
                'last_login_at' => $user?->last_login_at?->toIso8601String(),
                'last_admin_login_at' => $user?->last_admin_login_at?->toIso8601String(),
                'risk_score' => (int) ($events->max('risk_score') ?? 0),
                'total_events' => $events->count(),
                'payment_events' => $events->where('domain', 'payments')->count(),
                'admin_events' => $events->where('domain', 'admin')->count(),
                'successful_suspicious_events' => $events->where('outcome', 'success')->where('risk_score', '>=', 65)->count(),
            ],
            'summary' => [
                'source_ips' => $events->pluck('source_ip')->filter()->unique()->values()->all(),
                'domains' => $events->groupBy('domain')->map->count()->all(),
                'outcomes' => $events->groupBy('outcome')->map->count()->all(),
                'last_seen_at' => optional($events->first()?->occurred_at)->toIso8601String(),
            ],
            'events' => $this->serializeEventCollection($events),
        ];
    }

    public function incidents(): array
    {
        return ObservabilityIncident::query()
            ->with('owner:id,name,email')
            ->limit(50)
            ->get()
            ->sortByDesc(function (ObservabilityIncident $incident) {
                $metadata = $incident->metadata ?? [];
                $activity = collect($metadata['activity'] ?? []);
                $lastActivityAt = $activity->last()['performed_at'] ?? null;

                return $lastActivityAt
                    ? Carbon::parse($lastActivityAt)->timestamp
                    : ($incident->detected_at?->timestamp ?? 0);
            })
            ->values()
            ->map(fn (ObservabilityIncident $incident) => $this->serializeIncident($incident))
            ->all();
    }

    public function incidentSuggestions(array $filters): array
    {
        $events = $this->eventsQuery($filters)
            ->whereNull('incident_key')
            ->where('risk_score', '>=', 50)
            ->latest('occurred_at')
            ->limit(200)
            ->get();

        return $events
            ->groupBy(function (ObservabilityEvent $event) {
                $paymentReference = (string) ($event->details['payment_reference'] ?? '');
                if ($paymentReference !== '') {
                    return 'payment:'.$paymentReference;
                }

                $traceId = (string) ($event->trace_id ?? '');
                if ($traceId !== '') {
                    return 'trace:'.$traceId;
                }

                return implode('|', [
                    $event->domain ?: 'unknown',
                    $event->category ?: 'unknown',
                    $event->source_ip ?: 'unknown',
                    $event->target_route ?: 'unknown',
                    $event->attack_pattern ?: 'none',
                ]);
            })
            ->map(function (Collection $group, string $groupKey) {
                $ordered = $group->sortByDesc('occurred_at')->values();
                $latest = $ordered->first();

                if (! $latest || $ordered->count() < 2) {
                    return null;
                }

                $eventIds = $ordered->pluck('id')->values()->all();
                $severityRank = collect(['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4]);
                $highestSeverity = $ordered
                    ->sortByDesc(fn (ObservabilityEvent $event) => $severityRank[$event->severity] ?? 0)
                    ->first()?->severity ?? 'medium';

                $topRoutes = $ordered
                    ->pluck('target_route')
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->keys()
                    ->take(3)
                    ->values()
                    ->all();

                $topAttackPatterns = $ordered
                    ->pluck('attack_pattern')
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->keys()
                    ->take(3)
                    ->values()
                    ->all();

                $topActors = $ordered
                    ->map(fn (ObservabilityEvent $event) => $event->actor_label ?: $event->actor_id)
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->keys()
                    ->take(3)
                    ->values()
                    ->all();

                $sourceIps = $ordered
                    ->pluck('source_ip')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'suggestion_key' => 'sugg_'.md5($groupKey),
                    'title' => $this->suggestedIncidentTitle($latest, $ordered->count()),
                    'summary' => sprintf(
                        '%d related %s/%s events from %s',
                        $ordered->count(),
                        $latest->domain,
                        $latest->category,
                        $latest->source_ip ?: 'mixed sources'
                    ),
                    'severity' => $highestSeverity,
                    'status' => 'suggested',
                    'event_ids' => $eventIds,
                    'event_count' => count($eventIds),
                    'risk_score' => (int) round($ordered->avg('risk_score') ?? 0),
                    'first_seen_at' => $ordered->last()?->occurred_at?->toIso8601String(),
                    'last_seen_at' => $ordered->first()?->occurred_at?->toIso8601String(),
                    'domains' => $ordered->pluck('domain')->filter()->unique()->values()->all(),
                    'outcomes' => $ordered->pluck('outcome')->filter()->countBy()->all(),
                    'source_ips' => $sourceIps,
                    'top_routes' => $topRoutes,
                    'top_attack_patterns' => $topAttackPatterns,
                    'top_actors' => $topActors,
                    'sample_event' => $this->serializeEvent($latest),
                ];
            })
            ->filter()
            ->sortByDesc('risk_score')
            ->take(12)
            ->values()
            ->all();
    }

    public function incidentDetail(ObservabilityIncident $incident): array
    {
        $incident->loadMissing([
            'owner:id,name,email',
            'events.entities',
        ]);

        $events = $incident->events
            ->sortByDesc('occurred_at')
            ->values();

        $entities = $events
            ->flatMap(fn (ObservabilityEvent $event) => $event->entities)
            ->unique('id')
            ->values();

        return [
            'incident' => $this->serializeIncident($incident),
            'summary' => [
                'event_count' => $events->count(),
                'entity_count' => $entities->count(),
                'max_risk_score' => (int) ($events->max('risk_score') ?? 0),
                'sources' => $events->pluck('source_ip')->filter()->unique()->values()->all(),
                'domains' => $events->pluck('domain')->filter()->countBy()->all(),
                'outcomes' => $events->pluck('outcome')->filter()->countBy()->all(),
            ],
            'events' => $this->serializeEventCollection($events),
            'entities' => $entities->map(fn (ObservabilityEntity $entity) => $this->serializeEntity($entity))->values()->all(),
            'timeline' => $this->serializeEventCollection($events),
        ];
    }

    public function createIncident(array $payload): ObservabilityIncident
    {
        $metadata = $payload['metadata'] ?? [];

        $incident = ObservabilityIncident::query()->create([
            'incident_key' => 'inc_'.Str::lower(Str::random(12)),
            'title' => $payload['title'],
            'status' => $payload['status'] ?? 'open',
            'severity' => $payload['severity'] ?? 'medium',
            'owner_id' => $payload['owner_id'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'detected_at' => now(),
            'started_at' => $payload['started_at'] ?? now(),
            'metadata' => $metadata,
        ]);

        $eventIds = collect($payload['event_ids'] ?? [])->filter()->values()->all();
        if ($eventIds !== []) {
            $incident->events()->syncWithoutDetaching($eventIds);
            ObservabilityEvent::query()->whereIn('id', $eventIds)->update([
                'incident_key' => $incident->incident_key,
            ]);
        }

        $this->appendIncidentActivity($incident, 'created', [
            'event_ids' => $eventIds,
            'owner_id' => $incident->owner_id,
        ]);

        return $incident->fresh(['owner:id,name,email', 'events:id']);
    }

    public function updateIncident(ObservabilityIncident $incident, array $payload): ObservabilityIncident
    {
        $original = $incident->replicate();
        $originalEventIds = $incident->events()->pluck('observability_events.id')->map(fn ($id) => (int) $id)->values()->all();
        $originalMetadata = $incident->metadata ?? [];
        $incident->fill(collect($payload)->only([
            'title', 'status', 'severity', 'owner_id', 'summary', 'notes', 'started_at', 'resolved_at', 'metadata',
        ])->all());
        $incident->save();

        $activityContext = [];

        if (array_key_exists('event_ids', $payload)) {
            $eventIds = collect($payload['event_ids'] ?? [])->filter()->values()->all();
            $incident->events()->sync($eventIds);
            if ($eventIds !== []) {
                ObservabilityEvent::query()->whereIn('id', $eventIds)->update([
                    'incident_key' => $incident->incident_key,
                ]);
            }

            $activityContext['event_ids'] = $eventIds;
            $attachedEventIds = array_values(array_diff($eventIds, $originalEventIds));
            $detachedEventIds = array_values(array_diff($originalEventIds, $eventIds));

            if ($attachedEventIds !== []) {
                $activityContext['attached_event_ids'] = $attachedEventIds;
            }

            if ($detachedEventIds !== []) {
                $activityContext['detached_event_ids'] = $detachedEventIds;
            }
        }

        foreach (['status', 'severity', 'owner_id', 'notes'] as $field) {
            if (($original->{$field} ?? null) !== ($incident->{$field} ?? null)) {
                $activityContext['changes'][$field] = [
                    'from' => $original->{$field} ?? null,
                    'to' => $incident->{$field} ?? null,
                ];
            }
        }

        if (! empty($payload['append_note'])) {
            $this->appendIncidentNote($incident, (string) $payload['append_note']);
            $activityContext['note_appended'] = true;
        }

        if (($original->owner_id ?? null) !== ($incident->owner_id ?? null)) {
            $activityContext['owner'] = [
                'from' => $this->serializeIncidentOwner($original->owner_id),
                'to' => $this->serializeIncidentOwner($incident->owner_id),
            ];
        }

        if ($activityContext !== []) {
            $this->appendIncidentActivity($incident, 'updated', $activityContext);
        }

        return $incident->fresh(['owner:id,name,email', 'events:id']);
    }

    public function assignIncidentToCurrentUser(ObservabilityIncident $incident): ObservabilityIncident
    {
        $user = auth()->user();

        if (! $user) {
            return $incident->fresh(['owner:id,name,email', 'events:id']);
        }

        $incident->forceFill([
            'owner_id' => $user->id,
        ])->save();

        $this->appendIncidentActivity($incident, 'assigned', [
            'owner_id' => $user->id,
            'owner_name' => $user->name,
            'owner_email' => $user->email,
        ]);

        return $incident->fresh(['owner:id,name,email', 'events:id']);
    }

    public function releaseIncidentOwnership(ObservabilityIncident $incident): ObservabilityIncident
    {
        $previousOwner = $incident->owner;

        $incident->forceFill([
            'owner_id' => null,
        ])->save();

        $this->appendIncidentActivity($incident, 'released', [
            'previous_owner' => $previousOwner?->only(['id', 'name', 'email']),
        ]);

        return $incident->fresh(['owner:id,name,email', 'events:id']);
    }

    public function serializeIncident(ObservabilityIncident $incident): array
    {
        $metadata = $incident->metadata ?? [];
        $activity = collect($metadata['activity'] ?? []);
        $notes = collect($metadata['note_entries'] ?? []);

        return [
            'id' => $incident->id,
            'incident_key' => $incident->incident_key,
            'title' => $incident->title,
            'status' => $incident->status,
            'severity' => $incident->severity,
            'summary' => $incident->summary,
            'notes' => $incident->notes,
            'owner' => $incident->owner ? [
                'id' => $incident->owner->id,
                'name' => $incident->owner->name,
                'email' => $incident->owner->email,
            ] : null,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'started_at' => $incident->started_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'event_ids' => $incident->relationLoaded('events')
                ? $incident->events->pluck('id')->values()->all()
                : $incident->events()->pluck('observability_events.id')->values()->all(),
            'metadata' => $metadata,
            'note_count' => $notes->count(),
            'activity_count' => $activity->count(),
            'last_activity_at' => $activity->last()['performed_at'] ?? null,
        ];
    }

    protected function appendIncidentActivity(ObservabilityIncident $incident, string $action, array $context = []): void
    {
        $metadata = $incident->metadata ?? [];
        $activity = collect($metadata['activity'] ?? [])
            ->push([
                'action' => $action,
                'context' => $context,
                'performed_at' => now()->toIso8601String(),
                'performed_by' => auth()->user()?->only(['id', 'name', 'email']),
            ])
            ->take(-20)
            ->values()
            ->all();

        $metadata['activity'] = $activity;

        $incident->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    protected function appendIncidentNote(ObservabilityIncident $incident, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }

        $metadata = $incident->metadata ?? [];
        $notes = collect($metadata['note_entries'] ?? [])
            ->push([
                'body' => $note,
                'created_at' => now()->toIso8601String(),
                'created_by' => auth()->user()?->only(['id', 'name', 'email']),
            ])
            ->take(-20)
            ->values()
            ->all();

        $metadata['note_entries'] = $notes;

        $incident->forceFill([
            'notes' => $note,
            'metadata' => $metadata,
        ])->save();
    }

    protected function serializeIncidentOwner(?int $ownerId): ?array
    {
        if (! $ownerId) {
            return null;
        }

        $owner = User::query()->find($ownerId);

        return $owner?->only(['id', 'name', 'email']);
    }

    protected function collectorSummary(array $filters): array
    {
        $collectorEvents = $this->eventsQuery(array_merge($filters, ['domain' => ['system', 'db']]))
            ->where('source_type', 'collector')
            ->latest('occurred_at')
            ->limit(100)
            ->get();

        $thresholdMinutes = (int) config('services.observability.collector_stale_after_minutes', 15);
        $coverageTargets = ['ssh', 'sudo', 'process_execution', 'firewall'];
        $systemEvents = $collectorEvents->where('domain', 'system');
        $systemBreakdown = $systemEvents
            ->groupBy(fn (ObservabilityEvent $event) => $this->classifyCollectorSystemSignal($event))
            ->map(function (Collection $group, string $type) {
                return [
                    'type' => $type,
                    'events' => $group->count(),
                    'max_risk_score' => (int) ($group->max('risk_score') ?? 0),
                    'last_seen_at' => optional($group->sortByDesc('occurred_at')->first()?->occurred_at)->toIso8601String(),
                ];
            })
            ->sortByDesc('events')
            ->values();
        $streamSummary = $collectorEvents
            ->groupBy(fn (ObservabilityEvent $event) => (string) ($event->raw_ref['stream'] ?? 'unknown'))
            ->map(function (Collection $group, string $stream) {
                return [
                    'stream' => $stream,
                    'events' => $group->count(),
                    'hosts' => $group->map(fn (ObservabilityEvent $event) => (string) ($event->host ?: $event->target_resource_id ?: 'unknown'))->filter()->unique()->count(),
                    'max_risk_score' => (int) ($group->max('risk_score') ?? 0),
                ];
            })
            ->sortByDesc('events')
            ->values();

        $collectorHosts = $collectorEvents
            ->groupBy(fn (ObservabilityEvent $event) => (string) ($event->host ?: $event->target_resource_id ?: 'unknown'))
            ->map(function (Collection $hostEvents, string $host) use ($thresholdMinutes, $coverageTargets) {
                $lastEvent = $hostEvents->sortByDesc('occurred_at')->first();
                $lastSeenAt = $lastEvent?->occurred_at;
                $isStale = ! $lastSeenAt || $lastSeenAt->lt(now()->subMinutes($thresholdMinutes));
                $presentSignals = $hostEvents
                    ->where('domain', 'system')
                    ->map(fn (ObservabilityEvent $event) => $this->classifyCollectorSystemSignal($event))
                    ->filter(fn (string $type) => $type !== 'other')
                    ->unique()
                    ->values();
                $missingSignals = collect($coverageTargets)->reject(fn (string $type) => $presentSignals->contains($type))->values();
                $coverageScore = (int) round(($presentSignals->count() / max(1, count($coverageTargets))) * 100);

                return [
                    'host' => $host,
                    'events' => $hostEvents->count(),
                    'domains' => $hostEvents->pluck('domain')->filter()->unique()->values()->all(),
                    'streams' => $hostEvents->map(fn (ObservabilityEvent $event) => $event->raw_ref['stream'] ?? null)->filter()->unique()->values()->all(),
                    'max_risk_score' => (int) ($hostEvents->max('risk_score') ?? 0),
                    'max_severity' => (string) ($hostEvents->sortByDesc('risk_score')->first()?->severity ?? 'low'),
                    'last_seen_at' => optional($lastSeenAt)->toIso8601String(),
                    'status' => $isStale ? 'stale' : 'healthy',
                    'coverage_score' => $coverageScore,
                    'missing_signals' => $missingSignals->all(),
                ];
            })
            ->sortByDesc(fn (array $host) => ($host['status'] === 'stale' ? 1000 : 0) + $host['max_risk_score'] - $host['coverage_score'])
            ->values();

        $priorityAlerts = $collectorEvents
            ->sortByDesc(fn (ObservabilityEvent $event) => ((int) $event->risk_score * 10) + ($event->severity === 'critical' ? 50 : 0))
            ->take(8)
            ->values();

        $uncoveredSignals = collect($coverageTargets)
            ->reject(fn (string $type) => $systemBreakdown->pluck('type')->contains($type))
            ->values();

        return [
            'summary' => [
                'events' => $collectorEvents->count(),
                'hosts' => $collectorHosts->count(),
                'system_signals' => $collectorEvents->where('domain', 'system')->count(),
                'db_signals' => $collectorEvents->where('domain', 'db')->count(),
                'stale_sources' => $collectorHosts->where('status', 'stale')->count(),
                'healthy_sources' => $collectorHosts->where('status', 'healthy')->count(),
                'reporting_streams' => $streamSummary->count(),
                'telemetry_gaps' => max(0, 4 - $streamSummary->count()) + $collectorHosts->where('status', 'stale')->count(),
                'critical_system_signals' => $systemBreakdown->where('type', '!=', 'other')->sum('events'),
                'uncovered_signal_classes' => $uncoveredSignals->count(),
                'last_seen_at' => optional($collectorEvents->first()?->occurred_at)->toIso8601String(),
                'stale_after_minutes' => $thresholdMinutes,
            ],
            'hosts' => $collectorHosts->take(10)->all(),
            'stream_summary' => $streamSummary->take(8)->all(),
            'system_breakdown' => $systemBreakdown->take(8)->all(),
            'uncovered_signals' => $uncoveredSignals->all(),
            'priority_alerts' => $this->serializeEventCollection($priorityAlerts),
            'recent' => $this->serializeEventCollection($collectorEvents->take(10)),
        ];
    }

    protected function databaseCollectorSummary(array $filters): array
    {
        $events = $this->eventsQuery(array_merge($filters, ['domain' => ['db']]))
            ->where('source_type', 'collector')
            ->latest('occurred_at')
            ->limit(100)
            ->get();

        $classifier = function (ObservabilityEvent $event): string {
            $queryClass = strtolower((string) ($event->details['query_class'] ?? ''));
            $stream = strtolower((string) ($event->raw_ref['stream'] ?? ''));
            $pattern = strtolower((string) ($event->attack_pattern ?? ''));
            $signalType = strtolower((string) ($event->details['signal_type'] ?? ''));
            $title = strtolower((string) $event->title);
            $haystack = implode(' ', array_filter([$queryClass, $stream, $pattern, $signalType, $title]));

            return match (true) {
                str_contains($haystack, 'auth_failure') || str_contains($haystack, 'failed_auth') => 'auth_failures',
                str_contains($haystack, 'privileged_write') || str_contains($haystack, 'admin_write') || str_contains($haystack, 'privileged write') => 'privileged_writes',
                str_contains($haystack, 'schema_change') || str_contains($haystack, 'migration') || str_contains($haystack, 'ddl') => 'schema_changes',
                str_contains($haystack, 'destructive') || str_contains($haystack, 'truncate') || str_contains($haystack, 'drop') || str_contains($haystack, 'delete') => 'destructive_queries',
                $stream === 'database' && $queryClass === '' => 'other_db_signals',
                default => 'other_db_signals',
            };
        };

        $counts = $events->groupBy($classifier)->map->count();

        $breakdown = $events
            ->groupBy($classifier)
            ->map(function (Collection $group, string $type) {
                return [
                    'type' => $type,
                    'events' => $group->count(),
                    'max_risk_score' => (int) ($group->max('risk_score') ?? 0),
                    'last_seen_at' => optional($group->first()?->occurred_at)->toIso8601String(),
                ];
            })
            ->sortByDesc('events')
            ->values()
            ->all();

        return [
            'summary' => [
                'auth_failures' => (int) ($counts->get('auth_failures') ?? 0),
                'privileged_writes' => (int) ($counts->get('privileged_writes') ?? 0),
                'schema_changes' => (int) ($counts->get('schema_changes') ?? 0),
                'destructive_queries' => (int) ($counts->get('destructive_queries') ?? 0),
            ],
            'breakdown' => $breakdown,
            'priority_alerts' => $this->serializeEventCollection(
                $events
                    ->sortByDesc(fn (ObservabilityEvent $event) => ((int) $event->risk_score * 10) + ($event->severity === 'critical' ? 50 : 0))
                    ->take(8)
                    ->values()
            ),
            'recent' => $this->serializeEventCollection($events->take(10)),
        ];
    }

    protected function classifyCollectorSystemSignal(ObservabilityEvent $event): string
    {
        $stream = strtolower((string) ($event->raw_ref['stream'] ?? ''));
        $pattern = strtolower((string) ($event->attack_pattern ?? ''));
        $signalType = strtolower((string) ($event->details['signal_type'] ?? ''));
        $haystack = implode(' ', array_filter([$stream, $pattern, $signalType, strtolower($event->title)]));

        return match (true) {
            str_contains($haystack, 'ssh') => 'ssh',
            str_contains($haystack, 'sudo') => 'sudo',
            str_contains($haystack, 'cron') => 'cron',
            str_contains($haystack, 'process') || str_contains($haystack, 'exec') => 'process_execution',
            str_contains($haystack, 'firewall') || str_contains($haystack, 'blocklist') || str_contains($haystack, 'fail2ban') => 'firewall',
            str_contains($haystack, 'outbound') || str_contains($haystack, 'connection') || str_contains($haystack, 'egress') => 'outbound_connections',
            str_contains($haystack, 'package') || str_contains($haystack, 'apt') || str_contains($haystack, 'yum') => 'package_changes',
            str_contains($haystack, 'service') || str_contains($haystack, 'systemd') => 'service_changes',
            default => 'other',
        };
    }

    protected function inferCollectorAttackPattern(array $event, array $attack): ?string
    {
        $explicit = $attack['pattern'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $details = (array) ($event['details'] ?? []);
        $stream = strtolower((string) (($event['raw_ref']['stream'] ?? $details['collector_stream']) ?? ''));
        $signalType = strtolower((string) ($details['signal_type'] ?? ''));
        $title = strtolower((string) ($event['title'] ?? ''));
        $domain = strtolower((string) ($event['domain'] ?? ''));
        $haystack = implode(' ', array_filter([$stream, $signalType, $title]));

        if ($domain === 'db') {
            return match (true) {
                str_contains($haystack, 'auth') => 'auth_failure',
                str_contains($haystack, 'privileged') => 'privileged_write',
                str_contains($haystack, 'schema') || str_contains($haystack, 'migration') => 'schema_change',
                str_contains($haystack, 'truncate') || str_contains($haystack, 'drop') || str_contains($haystack, 'delete') => 'destructive_query',
                default => $signalType !== '' ? $signalType : ($stream !== '' ? $stream : null),
            };
        }

        return match (true) {
            str_contains($haystack, 'ssh') => 'ssh_failures',
            str_contains($haystack, 'sudo') => 'sudo_activity',
            str_contains($haystack, 'cron') => 'cron_change',
            str_contains($haystack, 'process') || str_contains($haystack, 'exec') => 'process_execution',
            str_contains($haystack, 'firewall') || str_contains($haystack, 'fail2ban') => 'firewall_change',
            str_contains($haystack, 'outbound') || str_contains($haystack, 'connection') => 'outbound_connection',
            default => $signalType !== '' ? $signalType : ($stream !== '' ? $stream : null),
        };
    }

    protected function suggestedIncidentTitle(ObservabilityEvent $event, int $count): string
    {
        if ($event->domain === 'payments' && $event->category === 'webhook') {
            $provider = (string) ($event->details['provider'] ?? 'Webhook');

            return sprintf('%s webhook cluster (%d events)', Str::headline($provider), $count);
        }

        if ($event->domain === 'auth') {
            return sprintf('Auth attack cluster on %s', $event->target_route ?: 'entry point');
        }

        if ($event->domain === 'bot') {
            return sprintf('Bot pressure cluster on %s', $event->target_route ?: 'public surface');
        }

        return sprintf('%s cluster on %s', Str::headline($event->domain ?: 'incident'), $event->target_route ?: 'multiple routes');
    }

    public function serializeEventCollection(Collection $events): array
    {
        return $events->map(fn (ObservabilityEvent $event) => $this->serializeEvent($event))->values()->all();
    }

    public function serializeEvent(ObservabilityEvent $event): array
    {
        return [
            'id' => $event->id,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'domain' => $event->domain,
            'category' => $event->category,
            'outcome' => $event->outcome,
            'severity' => $event->severity,
            'title' => $event->title,
            'summary' => $event->summary,
            'source' => [
                'ip' => $event->source_ip,
                'country' => $event->source_country,
                'asn' => $event->source_asn,
                'user_agent' => $event->source_user_agent,
            ],
            'actor' => [
                'type' => $event->actor_type,
                'id' => $event->actor_id,
                'label' => $event->actor_label,
            ],
            'target' => [
                'route' => $event->target_route,
                'method' => $event->target_method,
                'resource_type' => $event->target_resource_type,
                'resource_id' => $event->target_resource_id,
            ],
            'attack' => [
                'technique' => $event->attack_technique,
                'pattern' => $event->attack_pattern,
            ],
            'infra' => [
                'host' => $event->host,
                'environment' => $event->environment,
            ],
            'correlation' => [
                'request_id' => $event->request_id,
                'trace_id' => $event->trace_id,
                'session_id' => $event->session_id,
                'incident_id' => $event->incident_key,
            ],
            'risk' => [
                'score' => (int) $event->risk_score,
                'reasons' => $event->risk_reasons ?? [],
            ],
            'details' => $event->details ?? [],
            'raw_ref' => $event->raw_ref ?? [],
        ];
    }

    protected function serializeEntity(ObservabilityEntity $entity): array
    {
        return [
            'id' => $entity->id,
            'entity_key' => $entity->entity_key,
            'entity_type' => $entity->entity_type,
            'label' => $entity->label,
            'risk_score' => (int) $entity->risk_score,
            'first_seen_at' => $entity->first_seen_at?->toIso8601String(),
            'last_seen_at' => $entity->last_seen_at?->toIso8601String(),
            'metadata' => $entity->metadata ?? [],
        ];
    }

    protected function serializeAttacker(ObservabilityEntity $entity): array
    {
        $events = $entity->events();

        return [
            ...$this->serializeEntity($entity),
            'first_seen' => $entity->first_seen_at?->toIso8601String(),
            'last_seen' => $entity->last_seen_at?->toIso8601String(),
            'attempts' => (clone $events)->count(),
            'blocked' => (clone $events)->where('outcome', 'blocked')->count(),
            'successful' => (clone $events)->where('outcome', 'success')->count(),
            'routes' => (clone $events)->select('target_route')
                ->whereNotNull('target_route')
                ->distinct()
                ->limit(8)
                ->pluck('target_route')
                ->values()
                ->all(),
        ];
    }

    protected function eventsQuery(array $filters): Builder
    {
        $query = ObservabilityEvent::query();

        $query->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('occurred_at', '>=', Carbon::parse($from)));
        $query->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('occurred_at', '<=', Carbon::parse($to)));
        $query->when($filters['severity'] ?? null, fn (Builder $q, $values) => $q->whereIn('severity', (array) $values));
        $query->when($filters['domain'] ?? null, fn (Builder $q, $values) => $q->whereIn('domain', (array) $values));
        $query->when($filters['category'] ?? null, fn (Builder $q, $values) => $q->whereIn('category', (array) $values));
        $query->when($filters['outcome'] ?? null, fn (Builder $q, $values) => $q->whereIn('outcome', (array) $values));
        $query->when($filters['actor_type'] ?? null, fn (Builder $q, $values) => $q->whereIn('actor_type', (array) $values));
        $query->when($filters['route'] ?? null, fn (Builder $q, $route) => $q->where('target_route', 'like', '%'.$route.'%'));
        $query->when($filters['ip'] ?? null, fn (Builder $q, $ip) => $q->where('source_ip', $ip));
        $query->when($filters['asn'] ?? null, fn (Builder $q, $asn) => $q->where('source_asn', $asn));
        $query->when($filters['user_id'] ?? null, fn (Builder $q, $userId) => $q->where('actor_id', (string) $userId));
        $query->when($filters['admin_id'] ?? null, fn (Builder $q, $adminId) => $q->where('actor_type', 'admin')->where('actor_id', (string) $adminId));
        $query->when($filters['country'] ?? null, fn (Builder $q, $country) => $q->where('source_country', $country));
        $query->when($filters['host'] ?? null, function (Builder $q, $host) {
            $q->where(function (Builder $sub) use ($host) {
                $sub->where('host', $host)
                    ->orWhere('target_resource_id', $host)
                    ->orWhere('details->host', $host)
                    ->orWhere('raw_ref->host', $host);
            });
        });
        $query->when($filters['container'] ?? null, function (Builder $q, $container) {
            $q->where(function (Builder $sub) use ($container) {
                $sub->where('details->container', $container)
                    ->orWhere('raw_ref->container', $container);
            });
        });
        $query->when($filters['payment_reference'] ?? null, fn (Builder $q, $reference) => $q->where('details->payment_reference', $reference));
        $query->when($filters['incident_id'] ?? null, fn (Builder $q, $incident) => $q->where('incident_key', $incident));
        $query->when($filters['search'] ?? null, function (Builder $q, $search) {
            $q->where(function (Builder $sub) use ($search) {
                $sub->where('title', 'like', '%'.$search.'%')
                    ->orWhere('summary', 'like', '%'.$search.'%')
                    ->orWhere('source_ip', 'like', '%'.$search.'%')
                    ->orWhere('actor_label', 'like', '%'.$search.'%')
                    ->orWhere('target_route', 'like', '%'.$search.'%')
                    ->orWhere('source_country', 'like', '%'.$search.'%')
                    ->orWhere('host', 'like', '%'.$search.'%')
                    ->orWhere('target_resource_id', 'like', '%'.$search.'%');
            });
        });

        return $query;
    }

    protected function syncAuditEvents(): void
    {
        AuditLog::query()->with('user:id,name,email')->latest('id')->limit(300)->get()->each(function (AuditLog $log) {
            $action = strtolower((string) $log->action);
            $isWebhook = str_contains($action, 'webhook');
            $domain = $isWebhook
                ? 'payments'
                : (str_contains($action, 'auth') ? 'auth' : (str_contains($action, 'payment') ? 'payments' : 'audit'));
            $outcome = str_contains($action, 'signature_failed') || str_contains($action, 'missing_reference') || str_contains($action, 'payment_not_found')
                ? 'failed'
                : (str_contains($action, 'replayed') ? 'suspicious' : (str_contains($action, 'failed') ? 'failed' : 'success'));
            $severity = $isWebhook
                ? ((str_contains($action, 'signature_failed') || str_contains($action, 'payment_not_found')) ? 'high' : 'medium')
                : (str_contains($action, 'delete') || str_contains($action, 'permission') ? 'high' : 'medium');

            $this->upsertEvent([
                'event_key' => 'audit:'.$log->id,
                'source_type' => 'audit_log',
                'source_id' => (string) $log->id,
                'occurred_at' => $log->created_at ?? now(),
                'domain' => $domain,
                'category' => $isWebhook ? 'webhook' : (str_contains($action, 'auth') ? 'auth' : 'change'),
                'outcome' => $outcome,
                'severity' => $severity,
                'title' => Str::headline($log->action),
                'summary' => $log->url ?: 'Audit event recorded',
                'source_ip' => $log->ip_address,
                'source_user_agent' => $log->user_agent,
                'actor_type' => $log->user_id ? 'admin' : 'system',
                'actor_id' => $log->user_id ? (string) $log->user_id : null,
                'actor_label' => $log->user?->name ?? $log->user?->email ?? 'System',
                'target_route' => $log->url,
                'target_resource_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'target_resource_id' => $log->auditable_id ? (string) $log->auditable_id : null,
                'request_id' => $log->request_id,
                'trace_id' => $log->trace_id,
                'session_id' => $log->session_id,
                'attack_technique' => $isWebhook ? ($log->new_values['provider'] ?? $log->new_values['channel'] ?? 'webhook') : null,
                'attack_pattern' => $isWebhook
                    ? (str_contains($action, 'signature_failed') ? 'invalid_signature'
                        : (str_contains($action, 'payment_not_found') ? 'payment_not_found'
                        : (str_contains($action, 'replayed') ? 'replay' : 'callback')))
                    : null,
                'details' => [
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'suspicious_success' => $outcome === 'success' && $severity === 'high',
                    'provider' => $log->new_values['provider'] ?? $log->new_values['channel'] ?? null,
                ],
                'raw_ref' => ['table' => 'audit_logs', 'id' => $log->id],
            ]);
        });
    }

    protected function syncApiUsageEvents(): void
    {
        ApiUsageLog::query()->with('user:id,name,email')->latest('requested_at')->limit(500)->get()->each(function (ApiUsageLog $log) {
            $endpoint = strtolower((string) $log->endpoint);
            $statusCode = (int) $log->status_code;
            $domain = str_contains($endpoint, '/admin') ? 'admin'
                : (str_contains($endpoint, '/payments') || str_contains($endpoint, '/webhooks') ? 'payments'
                : (str_contains($endpoint, '/login') || str_contains($endpoint, '/auth') ? 'auth'
                : ($statusCode === 404 ? 'bot' : 'api')));

            $outcome = $statusCode >= 500 ? 'suspicious' : ($statusCode >= 400 ? 'blocked' : 'success');
            $severity = $statusCode >= 500 ? 'high' : (($statusCode >= 400 || $domain === 'bot') ? 'medium' : 'low');
            $pattern = $statusCode === 404 ? '404_scan' : ($statusCode === 429 ? 'rate_limit' : null);

            $this->upsertEvent([
                'event_key' => 'api:'.$log->id,
                'source_type' => 'api_usage_log',
                'source_id' => (string) $log->id,
                'occurred_at' => $log->requested_at ?? now(),
                'domain' => $domain,
                'category' => $domain === 'bot' ? 'bot' : 'request',
                'outcome' => $outcome,
                'severity' => $severity,
                'title' => sprintf('%s %s', $log->method, $log->endpoint),
                'summary' => sprintf('Status %s in %sms', $log->status_code, $log->response_time_ms),
                'source_ip' => $log->ip_address,
                'source_user_agent' => $log->user_agent,
                'actor_type' => $log->user_id ? 'user' : 'guest',
                'actor_id' => $log->user_id ? (string) $log->user_id : null,
                'actor_label' => $log->user?->name ?? $log->user?->email ?? 'Guest',
                'target_route' => $log->endpoint,
                'target_method' => $log->method,
                'attack_pattern' => $pattern,
                'request_id' => $log->request_id,
                'trace_id' => $log->trace_id,
                'session_id' => $log->session_id,
                'details' => [
                    'status_code' => $statusCode,
                    'response_time_ms' => $log->response_time_ms,
                    'rate_limited' => $statusCode === 429,
                ],
                'raw_ref' => ['table' => 'api_usage_logs', 'id' => $log->id],
            ]);
        });
    }

    protected function syncPaymentIssueEvents(): void
    {
        PaymentIssue::query()->with(['payment.user:id,name,email'])->latest('id')->limit(200)->get()->each(function (PaymentIssue $issue) {
            $payment = $issue->payment;
            if (! $payment) {
                return;
            }

            $this->upsertEvent([
                'event_key' => 'payment_issue:'.$issue->id,
                'source_type' => 'payment_issue',
                'source_id' => (string) $issue->id,
                'occurred_at' => $issue->created_at ?? now(),
                'domain' => 'payments',
                'category' => 'payment',
                'outcome' => in_array($issue->status, ['resolved', 'closed'], true) ? 'blocked' : 'suspicious',
                'severity' => $issue->severity ?? 'medium',
                'title' => $issue->title,
                'summary' => $issue->description ?: 'Payment risk event',
                'source_ip' => $issue->metadata['ip_address'] ?? null,
                'actor_type' => $payment->user_id ? 'user' : 'service',
                'actor_id' => $payment->user_id ? (string) $payment->user_id : null,
                'actor_label' => $payment->user?->name ?? $payment->user?->email ?? 'Payment service',
                'target_route' => '/api/payments/'.$payment->payment_type,
                'target_resource_type' => 'payment',
                'target_resource_id' => (string) $payment->id,
                'attack_technique' => $issue->issue_type,
                'request_id' => $issue->metadata['request_id'] ?? null,
                'trace_id' => $issue->metadata['trace_id'] ?? null,
                'session_id' => $issue->metadata['session_id'] ?? null,
                'details' => [
                    'payment_reference' => $payment->payment_reference,
                    'provider_status' => $issue->provider_status,
                    'signature_failed' => $issue->issue_type === PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE,
                    'suspicious_success' => $payment->status === 'completed' && $issue->status !== 'resolved',
                ],
                'raw_ref' => ['table' => 'payment_issues', 'id' => $issue->id],
            ]);
        });
    }

    protected function syncSystemSignals(): void
    {
        $deployment = $this->systemMonitoring->getDeploymentInfo();

        $this->upsertEvent([
            'event_key' => 'system:deployment:'.($deployment['git_commit'] ?? 'unknown'),
            'source_type' => 'system_monitoring',
            'source_id' => (string) ($deployment['deployment_id'] ?? 'unknown'),
            'occurred_at' => now(),
            'domain' => 'system',
            'category' => 'deployment',
            'outcome' => 'success',
            'severity' => 'low',
            'title' => 'Deployment Context',
            'summary' => sprintf('Branch %s on commit %s', $deployment['git_branch'] ?? 'unknown', $deployment['git_commit'] ?? 'unknown'),
            'actor_type' => 'service',
            'actor_label' => 'System monitor',
            'host' => $deployment['server_os'] ?? null,
            'details' => [
                'deployment_id' => $deployment['deployment_id'] ?? null,
                'git_branch' => $deployment['git_branch'] ?? null,
                'git_commit' => $deployment['git_commit'] ?? null,
                'last_migration' => $deployment['last_migration'] ?? null,
            ],
            'raw_ref' => ['source' => 'system_monitoring', 'type' => 'deployment'],
        ]);

        $lastMigration = $deployment['last_migration'] ?? null;
        if (is_array($lastMigration) && ! empty($lastMigration['name'])) {
            $this->upsertEvent([
                'event_key' => 'db:migration:'.$lastMigration['name'],
                'source_type' => 'migrations',
                'source_id' => (string) ($lastMigration['batch'] ?? $lastMigration['name']),
                'occurred_at' => now(),
                'domain' => 'db',
                'category' => 'change',
                'outcome' => 'success',
                'severity' => 'medium',
                'title' => 'Latest Migration Applied',
                'summary' => $lastMigration['name'],
                'actor_type' => 'service',
                'actor_label' => 'Migration runner',
                'target_resource_type' => 'migration',
                'target_resource_id' => (string) ($lastMigration['batch'] ?? ''),
                'details' => $lastMigration,
                'raw_ref' => ['source' => 'system_monitoring', 'type' => 'last_migration'],
            ]);
        }
    }

    protected function syncDatabaseSignals(): void
    {
        $slowQueries = collect(Cache::get('performance:slow_queries:'.now()->format('Y-m-d'), []))
            ->take(-25)
            ->values();

        $slowQueries->each(function (array $query) {
            $sql = (string) ($query['query'] ?? $query['sql'] ?? '');
            $timestamp = (string) ($query['timestamp'] ?? now()->toISOString());
            $time = (float) ($query['time'] ?? 0);

            $this->upsertEvent([
                'event_key' => 'db:slow_query:'.md5($sql.'|'.$timestamp),
                'source_type' => 'slow_query_cache',
                'source_id' => md5($sql.'|'.$timestamp),
                'occurred_at' => Carbon::parse($timestamp),
                'domain' => 'db',
                'category' => 'query',
                'outcome' => 'suspicious',
                'severity' => $time >= 1000 ? 'high' : 'medium',
                'title' => 'Slow Query Detected',
                'summary' => Str::limit($sql, 180),
                'actor_type' => 'service',
                'actor_label' => 'Database monitor',
                'attack_pattern' => 'slow_query',
                'details' => [
                    'query' => $sql,
                    'time_ms' => $time,
                ],
                'raw_ref' => ['source' => 'performance_cache', 'type' => 'slow_query'],
            ]);
        });
    }

    protected function syncIntegritySnapshots(): void
    {
        $paths = [
            ['category' => 'config', 'path' => base_path('.env')],
            ['category' => 'config', 'path' => config_path('app.php')],
            ['category' => 'config', 'path' => config_path('auth.php')],
            ['category' => 'config', 'path' => config_path('logging.php')],
            ['category' => 'bootstrap', 'path' => base_path('bootstrap/app.php')],
            ['category' => 'routes', 'path' => base_path('routes/api.php')],
        ];

        foreach ($paths as $item) {
            $path = $item['path'];
            $exists = File::exists($path);
            $hash = $exists ? hash_file('sha256', $path) : null;
            $snapshotKey = 'integrity:'.md5($path);
            $observedAt = now();

            $existing = ObservabilityIntegritySnapshot::query()->where('snapshot_key', $snapshotKey)->first();
            $status = ! $exists ? 'missing' : (($existing && $existing->hash !== null && $existing->hash !== $hash) ? 'changed' : 'stable');

            ObservabilityIntegritySnapshot::query()->updateOrCreate(
                ['snapshot_key' => $snapshotKey],
                [
                    'path' => $path,
                    'category' => $item['category'],
                    'hash' => $hash,
                    'status' => $status,
                    'host' => request()->getHost(),
                    'metadata' => [
                        'exists' => $exists,
                        'size' => $exists ? File::size($path) : null,
                        'modified_at' => $exists ? Carbon::createFromTimestamp(File::lastModified($path))->toIso8601String() : null,
                    ],
                    'observed_at' => $observedAt,
                ]
            );

            if ($status !== 'stable') {
                $this->upsertEvent([
                    'event_key' => 'integrity:'.md5($path.'|'.$status.'|'.$hash),
                    'source_type' => 'integrity_snapshot',
                    'source_id' => $snapshotKey,
                    'occurred_at' => $observedAt,
                    'domain' => 'system',
                    'category' => 'config',
                    'outcome' => $status === 'missing' ? 'failed' : 'suspicious',
                    'severity' => $status === 'missing' ? 'high' : 'medium',
                    'title' => 'Integrity Snapshot '.$status,
                    'summary' => $path,
                    'actor_type' => 'service',
                    'actor_label' => 'Integrity monitor',
                    'target_route' => $path,
                    'target_resource_type' => 'file',
                    'target_resource_id' => $snapshotKey,
                    'details' => [
                        'path' => $path,
                        'status' => $status,
                    ],
                    'raw_ref' => ['source' => 'integrity_snapshot', 'path' => $path],
                ]);
            }
        }
    }

    protected function syncEntryPoints(): void
    {
        $this->catalog->entryPoints()->each(function (array $definition) {
            $matchingEvents = ObservabilityEvent::query()
                ->when($definition['route_pattern'] ?? null, function (Builder $query, string $pattern) {
                    $tokens = array_filter(array_map('trim', explode('|', $pattern)));

                    $query->where(function (Builder $sub) use ($tokens) {
                        foreach ($tokens as $token) {
                            $needle = trim(str_replace(['POST ', 'GET ', 'PUT ', 'PATCH ', 'DELETE ', '*'], ['', '', '', '', '', '%'], $token));
                            if ($needle !== '') {
                                $sub->orWhere('target_route', 'like', '%'.$needle.'%');
                            }
                        }
                    });
                });

            ObservabilityEntryPoint::query()->updateOrCreate(
                ['entry_key' => $definition['entry_key']],
                [
                    'label' => $definition['label'],
                    'subsystem' => $definition['subsystem'],
                    'route_pattern' => $definition['route_pattern'],
                    'methods' => $definition['methods'],
                    'exposure_type' => $definition['exposure_type'],
                    'criticality' => $definition['criticality'],
                    'total_hits' => (clone $matchingEvents)->count(),
                    'unique_sources' => (clone $matchingEvents)->whereNotNull('source_ip')->distinct('source_ip')->count('source_ip'),
                    'blocked_hits' => (clone $matchingEvents)->where('outcome', 'blocked')->count(),
                    'failed_hits' => (clone $matchingEvents)->where('outcome', 'failed')->count(),
                    'successful_hits' => (clone $matchingEvents)->where('outcome', 'success')->count(),
                    'suspicious_hits' => (clone $matchingEvents)->where('outcome', 'suspicious')->count(),
                    'risk_score' => (int) round((clone $matchingEvents)->avg('risk_score') ?? 0),
                    'last_seen_at' => (clone $matchingEvents)->max('occurred_at'),
                    'metadata' => $definition['metadata'] ?? [],
                ]
            );
        });
    }

    protected function upsertEvent(array $payload): void
    {
        $risk = $this->risk->score($payload);
        $payload['risk_score'] = $risk['score'];
        $payload['risk_reasons'] = $risk['reasons'];
        $payload['host'] = $payload['host'] ?? request()->getHost();
        $payload['environment'] = $payload['environment'] ?? config('app.env');

        $event = ObservabilityEvent::query()->updateOrCreate(
            ['event_key' => $payload['event_key']],
            collect($payload)->except(['event_key'])->all()
        );

        $this->syncEntities($event);
    }

    protected function syncEntities(ObservabilityEvent $event): void
    {
        $entities = collect();

        if ($event->source_ip) {
            $entities->push([
                'entity_key' => 'ip:'.$event->source_ip,
                'entity_type' => 'ip',
                'label' => $event->source_ip,
                'relation' => 'source',
                'metadata' => [
                    'country' => $event->source_country,
                    'asn' => $event->source_asn,
                    'user_agent' => $event->source_user_agent,
                ],
            ]);
        }

        if ($event->actor_id) {
            $entities->push([
                'entity_key' => $event->actor_type.':'.$event->actor_id,
                'entity_type' => $event->actor_type,
                'label' => $event->actor_label ?? $event->actor_id,
                'relation' => 'actor',
                'metadata' => [],
            ]);
        }

        if ($event->session_id) {
            $entities->push([
                'entity_key' => 'session:'.$event->session_id,
                'entity_type' => 'session',
                'label' => $event->session_id,
                'relation' => 'session',
                'metadata' => [],
            ]);
        }

        if ($event->details['payment_reference'] ?? null) {
            $entities->push([
                'entity_key' => 'payment_reference:'.$event->details['payment_reference'],
                'entity_type' => 'payment_reference',
                'label' => $event->details['payment_reference'],
                'relation' => 'payment',
                'metadata' => [],
            ]);
        }

        $linkedKeys = [];

        $entities->each(function (array $entityData) use ($event, &$linkedKeys) {
            $existing = ObservabilityEntity::query()->where('entity_key', $entityData['entity_key'])->first();

            $entity = ObservabilityEntity::query()->updateOrCreate(
                ['entity_key' => $entityData['entity_key']],
                [
                    'entity_type' => $entityData['entity_type'],
                    'label' => $entityData['label'],
                    'risk_score' => max((int) $event->risk_score, (int) ($existing?->risk_score ?? 0)),
                    'first_seen_at' => $existing?->first_seen_at ?? $event->occurred_at,
                    'last_seen_at' => $event->occurred_at,
                    'metadata' => $entityData['metadata'],
                ]
            );

            $event->entities()->syncWithoutDetaching([$entity->id => ['relation' => $entityData['relation']]]);
            $linkedKeys[] = $entity->entity_key;
        });

        $event->forceFill([
            'linked_entity_keys' => $linkedKeys,
        ])->save();
    }
}
