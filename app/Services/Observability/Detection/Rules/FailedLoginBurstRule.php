<?php

namespace App\Services\Observability\Detection\Rules;

use App\Enums\Observability\EventSeverity;
use App\Models\ObservabilityEvent;
use App\Services\Observability\Detection\DetectionRule;
use App\Services\Observability\Detection\IncidentCandidate;
use Illuminate\Support\Carbon;

/**
 * Flags brute-force / credential-stuffing pressure: a single source IP
 * producing a burst of failed logins within the detection window.
 */
class FailedLoginBurstRule implements DetectionRule
{
    private const THRESHOLD = 8;

    public function key(): string
    {
        return 'failed_login_burst';
    }

    public function evaluate(Carbon $since): array
    {
        $events = ObservabilityEvent::query()
            ->where('domain', 'auth')
            ->where('category', 'login')
            ->where('outcome', 'failed')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('source_ip')
            ->get(['id', 'source_ip', 'actor_label']);

        $candidates = [];

        foreach ($events->groupBy('source_ip') as $ip => $group) {
            if ($group->count() < self::THRESHOLD) {
                continue;
            }

            $targetedAccounts = $group->pluck('actor_label')->filter()->unique()->count();
            $severity = $targetedAccounts >= 5 ? EventSeverity::Critical : EventSeverity::High;

            $candidates[] = new IncidentCandidate(
                ruleKey: $this->key(),
                entityKey: 'ip:'.$ip,
                title: 'Failed-login burst from '.$ip,
                severity: $severity,
                summary: $group->count().' failed logins from '.$ip.' targeting '
                    .$targetedAccounts.' account(s).',
                eventIds: $group->pluck('id')->all(),
                metadata: [
                    'source_ip' => $ip,
                    'attempts' => $group->count(),
                    'distinct_accounts' => $targetedAccounts,
                    'credential_stuffing' => $targetedAccounts >= 5,
                ],
            );
        }

        return $candidates;
    }
}
