<?php

namespace App\Services\Observability\Detection;

use App\Enums\Observability\EventSeverity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A correlated cluster of events that a {@see DetectionRule} considers worthy
 * of an incident.
 */
class IncidentCandidate
{
    /**
     * @param  list<int>  $eventIds
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $ruleKey,
        public readonly string $entityKey,
        public readonly string $title,
        public readonly EventSeverity $severity,
        public readonly string $summary,
        public readonly array $eventIds,
        public readonly array $metadata = [],
    ) {}

    /**
     * Deterministic incident key. Bucketed by day so a sustained attack folds
     * into one incident without re-opening incidents resolved on earlier days.
     */
    public function incidentKey(): string
    {
        $entity = Str::limit(Str::slug($this->entityKey, '_'), 60, '');

        return 'detect:'.$this->ruleKey.':'.$entity.':'.Carbon::now()->format('Ymd');
    }
}
