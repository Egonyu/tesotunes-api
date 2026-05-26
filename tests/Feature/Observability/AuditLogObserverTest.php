<?php

use App\Models\AuditLog;
use App\Models\ObservabilityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function observabilityEventForAudit(int $auditId): ?ObservabilityEvent
{
    return ObservabilityEvent::query()->where('event_key', 'audit:'.$auditId)->first();
}

it('mirrors a webhook signature failure into the payments domain', function () {
    $audit = AuditLog::create([
        'action' => 'mobile_money_webhook_signature_failed',
        'ip_address' => '198.51.100.30',
        'user_agent' => 'curl/8.0',
        'url' => '/api/webhooks/mobile-money',
    ]);

    $event = observabilityEventForAudit($audit->id);

    expect($event)->not->toBeNull()
        ->and($event->domain)->toBe('payments')
        ->and($event->category)->toBe('webhook')
        ->and($event->outcome)->toBe('failed')
        ->and($event->severity)->toBe('high');
});

it('mirrors a permission change into the integrity domain', function () {
    $audit = AuditLog::create([
        'action' => 'permission_grant',
        'url' => '/api/admin/roles/assign',
    ]);

    $event = observabilityEventForAudit($audit->id);

    expect($event)->not->toBeNull()
        ->and($event->domain)->toBe('integrity')
        ->and($event->category)->toBe('privilege');
});

it('flags destructive audit actions as high severity', function () {
    $audit = AuditLog::create([
        'action' => 'song_deleted',
        'url' => '/api/admin/songs/9',
    ]);

    $event = observabilityEventForAudit($audit->id);

    expect($event)->not->toBeNull()
        ->and($event->severity)->toBe('high')
        ->and($event->domain)->toBe('integrity');
});

it('maps a completed payment audit entry to a payment status change', function () {
    $audit = AuditLog::create([
        'action' => 'payment_completed',
        'url' => '/api/payments/wallet',
    ]);

    $event = observabilityEventForAudit($audit->id);

    expect($event)->not->toBeNull()
        ->and($event->domain)->toBe('payments')
        ->and($event->category)->toBe('payment');
});
