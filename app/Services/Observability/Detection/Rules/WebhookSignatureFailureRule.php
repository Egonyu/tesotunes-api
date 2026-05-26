<?php

namespace App\Services\Observability\Detection\Rules;

use App\Enums\Observability\EventSeverity;
use App\Models\ObservabilityEvent;
use App\Services\Observability\Detection\DetectionRule;
use App\Services\Observability\Detection\IncidentCandidate;
use Illuminate\Support\Carbon;

/**
 * Flags payment-webhook tampering: repeated signature-verification failures,
 * which indicate a forged-callback or replay attempt against the payment rail.
 */
class WebhookSignatureFailureRule implements DetectionRule
{
    private const THRESHOLD = 3;

    public function key(): string
    {
        return 'webhook_signature_failure';
    }

    public function evaluate(Carbon $since): array
    {
        $events = ObservabilityEvent::query()
            ->where('domain', 'payments')
            ->where('category', 'webhook')
            ->whereIn('outcome', ['failed', 'suspicious'])
            ->where('occurred_at', '>=', $since)
            ->get(['id', 'source_ip']);

        if ($events->count() < self::THRESHOLD) {
            return [];
        }

        $ips = $events->pluck('source_ip')->filter()->unique()->values()->all();

        return [
            new IncidentCandidate(
                ruleKey: $this->key(),
                entityKey: 'payment_webhook',
                title: 'Payment webhook signature failures',
                severity: $events->count() >= 10 ? EventSeverity::Critical : EventSeverity::High,
                summary: $events->count().' webhook signature/replay failures from '
                    .count($ips).' source IP(s).',
                eventIds: $events->pluck('id')->all(),
                metadata: [
                    'failures' => $events->count(),
                    'source_ips' => $ips,
                ],
            ),
        ];
    }
}
