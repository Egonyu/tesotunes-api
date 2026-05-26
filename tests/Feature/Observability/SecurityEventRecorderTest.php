<?php

use App\Enums\Observability\SecurityEventType;
use App\Jobs\Observability\RecordSecurityEvent;
use App\Models\ObservabilityEntity;
use App\Models\ObservabilityEvent;
use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->recorder = app(SecurityEventRecorder::class);
});

it('persists a normalized observability event from the taxonomy', function () {
    $event = $this->recorder->record(
        SecurityEvent::of(SecurityEventType::AuthLoginFailed)
            ->summary('Failed login for jane@example.com')
            ->actor('guest', null, 'jane@example.com')
            ->source('198.51.100.20')
            ->target('/api/login', 'POST'),
    );

    expect($event->domain)->toBe('auth')
        ->and($event->category)->toBe('login')
        ->and($event->outcome)->toBe('failed')
        ->and($event->severity)->toBe('medium')
        ->and($event->risk_score)->toBeGreaterThan(0)
        ->and($event->details['event_type'])->toBe('auth.login.failed');

    $this->assertDatabaseHas('observability_events', [
        'id' => $event->id,
        'domain' => 'auth',
        'category' => 'login',
    ]);
});

it('links the source IP as an observability entity', function () {
    $event = $this->recorder->record(
        SecurityEvent::of(SecurityEventType::ApiForbiddenProbe)
            ->source('203.0.113.55')
            ->target('/api/admin/users', 'GET'),
    );

    $entity = ObservabilityEntity::query()->where('entity_key', 'ip:203.0.113.55')->first();

    expect($entity)->not->toBeNull()
        ->and($entity->entity_type)->toBe('ip')
        ->and($event->fresh()->linked_entity_keys)->toContain('ip:203.0.113.55');

    $this->assertDatabaseHas('observability_event_entities', [
        'event_id' => $event->id,
        'entity_id' => $entity->id,
        'relation' => 'source',
    ]);
});

it('deduplicates events that share an explicit event key', function () {
    $build = fn () => SecurityEvent::of(SecurityEventType::PaymentWebhookReplayed)
        ->eventKey('webhook:replay:fixed-key')
        ->source('198.51.100.9');

    $this->recorder->record($build());
    $this->recorder->record($build());

    expect(ObservabilityEvent::query()->where('event_key', 'webhook:replay:fixed-key')->count())->toBe(1);
});

it('records a security event when the queued job runs', function () {
    $payload = SecurityEvent::of(SecurityEventType::IntegrityBulkDeletion)
        ->actor('admin', 7, 'Ops')
        ->summary('Deleted 40 songs')
        ->toArray();

    (new RecordSecurityEvent($payload))->handle($this->recorder);

    $this->assertDatabaseHas('observability_events', [
        'domain' => 'integrity',
        'category' => 'change',
        'severity' => 'high',
    ]);
});
