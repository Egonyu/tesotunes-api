<?php

namespace App\Services\Observability\Risk;

use App\Enums\Observability\EventOutcome;
use App\Enums\Observability\SecurityDomain;
use App\Enums\Observability\SecurityEventType;
use App\Services\Observability\SecurityEvent;

/**
 * Turns a {@see SecurityEvent} into a 0-100 risk score plus the list of
 * reasons that drove it. Rule-based and deterministic — replaces the old
 * heuristic ObservabilityRiskService.
 */
class SecurityRiskScorer
{
    /**
     * @return array{score: int, reasons: list<string>}
     */
    public function score(SecurityEvent $event): array
    {
        $score = 0;
        $reasons = [];

        $severity = $event->resolvedSeverity();
        $outcome = $event->resolvedOutcome();
        $domain = $event->type->domain();

        $score += $severity->weight();

        // Outcome alone stays modest — routine successes must score low. The
        // danger of a *successful attack* is captured by the high-severity
        // success boost below, not by the outcome weight.
        $score += match ($outcome) {
            EventOutcome::Suspicious => 22,
            EventOutcome::Failed => 14,
            EventOutcome::Blocked => 10,
            EventOutcome::Success => 8,
        };

        $score += match ($domain) {
            SecurityDomain::Payments, SecurityDomain::Integrity => 14,
            SecurityDomain::Auth, SecurityDomain::System => 10,
            SecurityDomain::Api => 8,
        };

        $route = strtolower((string) $event->targetRoute);
        $actorType = strtolower((string) $event->actorType);
        $details = $event->details;

        $this->boost(
            $route !== '' && str_contains($route, '/admin'),
            12, 'admin-surface-targeted', $score, $reasons,
        );
        $this->boost(
            $route !== '' && (str_contains($route, '/payment') || str_contains($route, '/webhook')),
            9, 'payment-surface-targeted', $score, $reasons,
        );
        $this->boost(
            in_array($actorType, ['admin', 'super_admin', 'service'], true),
            12, 'privileged-actor', $score, $reasons,
        );
        $this->boost(
            $outcome === EventOutcome::Success && $severity->atLeast(\App\Enums\Observability\EventSeverity::High),
            18, 'high-severity-success', $score, $reasons,
        );

        // Type-specific weighting for the highest-signal events.
        $score += match ($event->type) {
            SecurityEventType::AuthLoginSuspicious => $this->withReason(20, 'suspicious-authenticated-session', $reasons),
            SecurityEventType::PaymentWebhookSignatureFailed => $this->withReason(14, 'webhook-signature-invalid', $reasons),
            SecurityEventType::PaymentWebhookReplayed => $this->withReason(16, 'webhook-replay-detected', $reasons),
            SecurityEventType::IntegrityPrivilegeChanged,
            SecurityEventType::IntegritySensitiveFieldChanged => $this->withReason(14, 'privilege-surface-modified', $reasons),
            SecurityEventType::IntegrityBulkDeletion => $this->withReason(14, 'bulk-destructive-action', $reasons),
            SecurityEventType::ApiForbiddenProbe => $this->withReason(10, 'forbidden-endpoint-probed', $reasons),
            default => 0,
        };

        // Detail flags carried by the touchpoint.
        $this->boost(($details['known_bad_source'] ?? false) === true, 16, 'known-bad-source', $score, $reasons);
        $this->boost(($details['tor'] ?? false) === true, 12, 'tor-exit-node', $score, $reasons);
        $this->boost(($details['proxy'] ?? false) === true, 6, 'anonymizing-proxy', $score, $reasons);
        $this->boost(($details['datacenter'] ?? false) === true, 7, 'datacenter-source', $score, $reasons);
        $this->boost(((int) ($details['repeat_velocity'] ?? 0)) >= 10, 10, 'high-repeat-velocity', $score, $reasons);
        $this->boost(($details['new_device'] ?? false) === true, 8, 'unrecognized-device', $score, $reasons);
        $this->boost(($details['impossible_travel'] ?? false) === true, 18, 'impossible-travel', $score, $reasons);

        return [
            'score' => max(0, min(100, $score)),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    private function boost(bool $condition, int $points, string $reason, int &$score, array &$reasons): void
    {
        if (! $condition) {
            return;
        }

        $score += $points;
        $reasons[] = $reason;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function withReason(int $points, string $reason, array &$reasons): int
    {
        $reasons[] = $reason;

        return $points;
    }
}
