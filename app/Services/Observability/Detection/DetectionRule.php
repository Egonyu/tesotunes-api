<?php

namespace App\Services\Observability\Detection;

use Illuminate\Support\Carbon;

/**
 * A detection rule inspects recently recorded security events and returns
 * incident candidates for anything that crosses its threshold.
 */
interface DetectionRule
{
    /**
     * Stable identifier, used to build deterministic incident keys.
     */
    public function key(): string;

    /**
     * @return list<IncidentCandidate>
     */
    public function evaluate(Carbon $since): array;
}
