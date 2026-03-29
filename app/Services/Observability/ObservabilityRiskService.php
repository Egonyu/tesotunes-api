<?php

namespace App\Services\Observability;

class ObservabilityRiskService
{
    public function score(array $payload): array
    {
        $score = 0;
        $reasons = [];

        $severityWeights = [
            'low' => 10,
            'medium' => 25,
            'high' => 45,
            'critical' => 65,
        ];

        $outcomeWeights = [
            'blocked' => 10,
            'failed' => 14,
            'suspicious' => 26,
            'success' => 36,
        ];

        $domainWeights = [
            'payments' => 12,
            'auth' => 10,
            'admin' => 12,
            'db' => 14,
            'system' => 12,
            'bot' => 8,
        ];

        $severity = $payload['severity'] ?? 'low';
        $outcome = $payload['outcome'] ?? 'failed';
        $domain = $payload['domain'] ?? 'app';
        $route = strtolower((string) ($payload['target_route'] ?? ''));
        $actorType = strtolower((string) ($payload['actor_type'] ?? 'guest'));
        $details = $payload['details'] ?? [];

        $score += $severityWeights[$severity] ?? 10;
        $score += $outcomeWeights[$outcome] ?? 10;
        $score += $domainWeights[$domain] ?? 0;

        $this->maybeBoost($route !== '' && str_contains($route, '/admin'), 12, 'admin-route-targeted', $score, $reasons);
        $this->maybeBoost($route !== '' && str_contains($route, '/payments'), 10, 'payment-route-targeted', $score, $reasons);
        $this->maybeBoost($route !== '' && str_contains($route, '/webhooks'), 9, 'webhook-route-targeted', $score, $reasons);
        $this->maybeBoost($route !== '' && str_contains($route, '/auth'), 8, 'auth-route-targeted', $score, $reasons);
        $this->maybeBoost(in_array($actorType, ['admin', 'service'], true), 12, 'privileged-actor', $score, $reasons);
        $this->maybeBoost(($details['known_bad'] ?? false) === true, 14, 'known-bad-source', $score, $reasons);
        $this->maybeBoost(($details['proxy'] ?? false) === true, 6, 'proxy-source', $score, $reasons);
        $this->maybeBoost(($details['tor'] ?? false) === true, 12, 'tor-source', $score, $reasons);
        $this->maybeBoost(($details['datacenter'] ?? false) === true, 7, 'datacenter-source', $score, $reasons);
        $this->maybeBoost(($details['repeat_velocity'] ?? 0) >= 10, 8, 'repeat-velocity', $score, $reasons);
        $this->maybeBoost(($details['signature_failed'] ?? false) === true, 10, 'signature-validation-failed', $score, $reasons);
        $this->maybeBoost(($details['rate_limited'] ?? false) === true, 4, 'rate-limit-triggered', $score, $reasons);
        $this->maybeBoost(($details['suspicious_success'] ?? false) === true, 18, 'suspicious-success', $score, $reasons);

        return [
            'score' => min(100, max(0, $score)),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    protected function maybeBoost(bool $condition, int $points, string $reason, int &$score, array &$reasons): void
    {
        if (! $condition) {
            return;
        }

        $score += $points;
        $reasons[] = $reason;
    }
}
