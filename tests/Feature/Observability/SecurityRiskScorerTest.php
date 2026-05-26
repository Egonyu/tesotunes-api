<?php

use App\Enums\Observability\EventOutcome;
use App\Enums\Observability\EventSeverity;
use App\Enums\Observability\SecurityEventType;
use App\Services\Observability\Risk\SecurityRiskScorer;
use App\Services\Observability\SecurityEvent;

beforeEach(function () {
    $this->scorer = new SecurityRiskScorer;
});

it('scores a routine successful login as low risk', function () {
    $result = $this->scorer->score(
        SecurityEvent::of(SecurityEventType::AuthLoginSucceeded)
            ->actor('user', 1, 'Jane')
            ->source('203.0.113.10'),
    );

    expect($result['score'])->toBeLessThan(40);
});

it('scores a webhook signature failure as high risk with a reason', function () {
    $result = $this->scorer->score(
        SecurityEvent::of(SecurityEventType::PaymentWebhookSignatureFailed)
            ->source('198.51.100.7')
            ->target('/api/webhooks/payment/zengapay', 'POST'),
    );

    expect($result['score'])->toBeGreaterThanOrEqual(70)
        ->and($result['reasons'])->toContain('webhook-signature-invalid');
});

it('treats a successful privilege change as a top-tier risk', function () {
    $result = $this->scorer->score(
        SecurityEvent::of(SecurityEventType::IntegrityPrivilegeChanged)
            ->actor('admin', 9, 'Root')
            ->target('/api/admin/roles/assign', 'POST'),
    );

    expect($result['score'])->toBeGreaterThanOrEqual(85)
        ->and($result['reasons'])->toContain('high-severity-success')
        ->and($result['reasons'])->toContain('privileged-actor');
});

it('clamps the score to the 0-100 range', function () {
    $result = $this->scorer->score(
        SecurityEvent::of(SecurityEventType::AuthLoginSuspicious)
            ->severity(EventSeverity::Critical)
            ->outcome(EventOutcome::Success)
            ->actor('admin', 1, 'Root')
            ->target('/api/admin/users', 'POST')
            ->details([
                'known_bad_source' => true,
                'tor' => true,
                'impossible_travel' => true,
                'new_device' => true,
            ]),
    );

    expect($result['score'])->toBe(100);
});
