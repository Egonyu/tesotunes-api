<?php

namespace App\Services\Observability\Detection\Rules;

use App\Enums\Observability\EventSeverity;
use App\Models\ObservabilityEvent;
use App\Services\Observability\Detection\DetectionRule;
use App\Services\Observability\Detection\IncidentCandidate;
use Illuminate\Support\Carbon;

/**
 * Flags insider-risk: a single actor performing a burst of destructive
 * changes (deletes / purges) within the detection window.
 */
class BulkDeletionRule implements DetectionRule
{
    private const THRESHOLD = 5;

    public function key(): string
    {
        return 'bulk_deletion';
    }

    public function evaluate(Carbon $since): array
    {
        $events = ObservabilityEvent::query()
            ->where('domain', 'integrity')
            ->where('category', 'change')
            ->where('severity', 'high')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('actor_id')
            ->get(['id', 'actor_id', 'actor_label', 'actor_type']);

        $candidates = [];

        foreach ($events->groupBy('actor_id') as $actorId => $group) {
            if ($group->count() < self::THRESHOLD) {
                continue;
            }

            $actorLabel = $group->first()->actor_label ?? ('User #'.$actorId);
            $actorType = $group->first()->actor_type ?? 'user';

            $candidates[] = new IncidentCandidate(
                ruleKey: $this->key(),
                entityKey: $actorType.':'.$actorId,
                title: 'Bulk destructive activity by '.$actorLabel,
                severity: $group->count() >= 20 ? EventSeverity::Critical : EventSeverity::High,
                summary: $actorLabel.' performed '.$group->count().' destructive changes.',
                eventIds: $group->pluck('id')->all(),
                metadata: [
                    'actor_id' => $actorId,
                    'actor_label' => $actorLabel,
                    'destructive_actions' => $group->count(),
                ],
            );
        }

        return $candidates;
    }
}
