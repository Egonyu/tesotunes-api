<?php

use App\Enums\Observability\SecurityEventType;
use App\Models\ObservabilityEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Isolate from observability_events created by other suites via the
    // AuditLogObserver / api-group MonitorApiAbuse middleware.
    ObservabilityEvent::query()->delete();
});

function actingAsSecurityAdmin(): User
{
    $admin = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5],
    );
    $admin->roles()->attach($role->id, ['assigned_at' => now(), 'is_active' => true]);
    Sanctum::actingAs($admin);

    return $admin;
}

function seedConsoleEvents(): void
{
    $recorder = app(SecurityEventRecorder::class);
    $recorder->record(SecurityEvent::of(SecurityEventType::AuthLoginFailed)->source('198.51.100.1')->actor('guest', null, 'x@y.com'));
    $recorder->record(SecurityEvent::of(SecurityEventType::PaymentWebhookSignatureFailed)->source('198.51.100.2'));
    $recorder->record(SecurityEvent::of(SecurityEventType::ApiForbiddenProbe)->source('198.51.100.3')->target('/api/admin/users', 'GET'));
}

it('rejects unauthenticated access to the security console', function () {
    $this->getJson('/api/admin/observability/console/posture')->assertUnauthorized();
});

it('returns posture KPIs for an admin', function () {
    actingAsSecurityAdmin();
    seedConsoleEvents();

    $response = $this->getJson('/api/admin/observability/console/posture')->assertOk();

    $response->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'window' => ['from', 'to'],
                'kpis' => ['open_incidents', 'events', 'failed_logins', 'webhook_failures'],
                'by_domain',
                'by_severity',
                'top_risk_entities',
            ],
        ]);

    expect($response->json('data.kpis.events'))->toBe(3)
        ->and($response->json('data.kpis.failed_logins'))->toBe(1)
        ->and($response->json('data.kpis.webhook_failures'))->toBe(1);
});

it('returns a paginated event feed', function () {
    actingAsSecurityAdmin();
    seedConsoleEvents();

    $response = $this->getJson('/api/admin/observability/console/feed')->assertOk();

    $response->assertJsonStructure([
        'data' => [['id', 'domain', 'severity', 'risk' => ['score', 'reasons'], 'actor', 'source']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);

    expect($response->json('meta.total'))->toBe(3);
});

it('filters the feed by domain', function () {
    actingAsSecurityAdmin();
    seedConsoleEvents();

    $response = $this->getJson('/api/admin/observability/console/feed?domain[]=payments')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.domain'))->toBe('payments');
});

it('returns a per-domain summary and 404s on an unknown domain', function () {
    actingAsSecurityAdmin();
    seedConsoleEvents();

    $this->getJson('/api/admin/observability/console/domain/auth')
        ->assertOk()
        ->assertJsonPath('data.domain', 'auth')
        ->assertJsonPath('data.total', 1);

    $this->getJson('/api/admin/observability/console/domain/not-a-domain')->assertNotFound();
});

it('lists incidents for an admin', function () {
    actingAsSecurityAdmin();

    $this->getJson('/api/admin/observability/console/incidents')
        ->assertOk()
        ->assertJsonPath('success', true);
});
