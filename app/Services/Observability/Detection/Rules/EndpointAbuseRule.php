<?php

namespace App\Services\Observability\Detection\Rules;

use App\Enums\Observability\EventSeverity;
use App\Models\ObservabilityEvent;
use App\Services\Observability\Detection\DetectionRule;
use App\Services\Observability\Detection\IncidentCandidate;
use Illuminate\Support\Carbon;

/**
 * Flags API abuse: a single source IP racking up forbidden probes and / or
 * rate-limit breaches — the signature of scraping or endpoint enumeration.
 */
class EndpointAbuseRule implements DetectionRule
{
    private const THRESHOLD = 15;

    public function key(): string
    {
        return 'endpoint_abuse';
    }

    public function evaluate(Carbon $since): array
    {
        $events = ObservabilityEvent::query()
            ->where('domain', 'api')
            ->whereIn('category', ['access', 'rate_limit', 'scan', 'bot'])
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('source_ip')
            ->get(['id', 'source_ip', 'target_route']);

        $candidates = [];

        foreach ($events->groupBy('source_ip') as $ip => $group) {
            if ($group->count() < self::THRESHOLD) {
                continue;
            }

            $routes = $group->pluck('target_route')->filter()->unique()->count();

            $candidates[] = new IncidentCandidate(
                ruleKey: $this->key(),
                entityKey: 'ip:'.$ip,
                title: 'API abuse from '.$ip,
                severity: $group->count() >= 50 ? EventSeverity::Critical : EventSeverity::High,
                summary: $group->count().' blocked/abusive requests from '.$ip.' across '
                    .$routes.' route(s).',
                eventIds: $group->pluck('id')->all(),
                metadata: [
                    'source_ip' => $ip,
                    'requests' => $group->count(),
                    'distinct_routes' => $routes,
                ],
            );
        }

        return $candidates;
    }
}
