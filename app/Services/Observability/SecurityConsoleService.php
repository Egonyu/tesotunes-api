<?php

namespace App\Services\Observability;

use App\Models\ObservabilityEntity;
use App\Models\ObservabilityEvent;
use App\Models\ObservabilityIncident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the security console. Every method queries data the
 * push pipeline already recorded — there is no sync-on-read, so dashboard
 * requests stay cheap regardless of traffic volume.
 */
class SecurityConsoleService
{
    /**
     * Headline posture KPIs for the overview screen.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function posture(array $filters): array
    {
        [$from, $to] = $this->window($filters);
        $events = fn (): Builder => ObservabilityEvent::query()->whereBetween('occurred_at', [$from, $to]);
        $openIncidents = fn (): Builder => ObservabilityIncident::query()->whereNotIn('status', ['resolved', 'closed']);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'kpis' => [
                'open_incidents' => $openIncidents()->count(),
                'critical_incidents' => $openIncidents()->where('severity', 'critical')->count(),
                'events' => $events()->count(),
                'high_risk_events' => $events()->where('risk_score', '>=', 70)->count(),
                'failed_logins' => $events()
                    ->where('domain', 'auth')->where('category', 'login')->where('outcome', 'failed')->count(),
                'webhook_failures' => $events()
                    ->where('domain', 'payments')->where('category', 'webhook')
                    ->whereIn('outcome', ['failed', 'suspicious'])->count(),
                'blocked_api' => $events()->where('domain', 'api')->count(),
            ],
            'by_domain' => $events()
                ->selectRaw('domain, count(*) as total')
                ->groupBy('domain')->pluck('total', 'domain'),
            'by_severity' => $events()
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')->pluck('total', 'severity'),
            'top_risk_entities' => ObservabilityEntity::query()
                ->where('risk_score', '>', 0)
                ->orderByDesc('risk_score')
                ->limit(5)
                ->get(['entity_key', 'entity_type', 'label', 'risk_score', 'last_seen_at'])
                ->map(fn (ObservabilityEntity $e): array => [
                    'entity_key' => $e->entity_key,
                    'entity_type' => $e->entity_type,
                    'label' => $e->label,
                    'risk_score' => (int) $e->risk_score,
                    'last_seen_at' => $e->last_seen_at?->toIso8601String(),
                ]),
        ];
    }

    /**
     * Paginated, filtered event feed.
     *
     * @param  array<string, mixed>  $filters
     */
    public function feed(array $filters, int $perPage): LengthAwarePaginator
    {
        [$from, $to] = $this->window($filters);

        return $this->applyFilters(
            ObservabilityEvent::query()->whereBetween('occurred_at', [$from, $to]),
            $filters,
        )
            ->orderByDesc('occurred_at')
            ->paginate($perPage);
    }

    /**
     * Open + recently resolved incidents with their linked-event counts.
     */
    public function incidents(): Collection
    {
        return ObservabilityIncident::query()
            ->withCount('events')
            ->with('owner:id,name,email')
            ->orderByRaw("FIELD(status, 'open', 'investigating', 'acknowledged', 'resolved', 'closed')")
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get()
            ->map(fn (ObservabilityIncident $incident): array => [
                'id' => $incident->id,
                'incident_key' => $incident->incident_key,
                'title' => $incident->title,
                'status' => $incident->status,
                'severity' => $incident->severity,
                'summary' => $incident->summary,
                'event_count' => $incident->events_count,
                'owner' => $incident->owner
                    ? ['id' => $incident->owner->id, 'name' => $incident->owner->name]
                    : null,
                'detected_at' => $incident->detected_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'metadata' => $incident->metadata ?? [],
            ]);
    }

    /**
     * Per-domain summary: outcome/category breakdown and the recent feed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function domainSummary(string $domain, array $filters): array
    {
        [$from, $to] = $this->window($filters);
        $scoped = fn (): Builder => ObservabilityEvent::query()
            ->where('domain', $domain)
            ->whereBetween('occurred_at', [$from, $to]);

        return [
            'domain' => $domain,
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'total' => $scoped()->count(),
            'by_outcome' => $scoped()
                ->selectRaw('outcome, count(*) as total')
                ->groupBy('outcome')->pluck('total', 'outcome'),
            'by_category' => $scoped()
                ->selectRaw('category, count(*) as total')
                ->groupBy('category')->pluck('total', 'category'),
            'top_sources' => $scoped()
                ->whereNotNull('source_ip')
                ->selectRaw('source_ip, count(*) as total, max(risk_score) as max_risk')
                ->groupBy('source_ip')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->map(fn ($row): array => [
                    'ip' => $row->source_ip,
                    'events' => (int) $row->total,
                    'max_risk' => (int) $row->max_risk,
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['domain'])) {
            $query->whereIn('domain', (array) $filters['domain']);
        }
        if (! empty($filters['severity'])) {
            $query->whereIn('severity', (array) $filters['severity']);
        }
        if (! empty($filters['outcome'])) {
            $query->whereIn('outcome', (array) $filters['outcome']);
        }
        if (! empty($filters['min_risk'])) {
            $query->where('risk_score', '>=', (int) $filters['min_risk']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(function (Builder $sub) use ($term) {
                $sub->where('title', 'like', $term)
                    ->orWhere('summary', 'like', $term)
                    ->orWhere('source_ip', 'like', $term)
                    ->orWhere('actor_label', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(array $filters): array
    {
        $to = ! empty($filters['to']) ? Carbon::parse((string) $filters['to']) : Carbon::now();
        $from = ! empty($filters['from'])
            ? Carbon::parse((string) $filters['from'])
            : (clone $to)->subDay();

        return [$from, $to];
    }
}
