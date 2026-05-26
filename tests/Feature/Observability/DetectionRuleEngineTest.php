<?php

use App\Enums\Observability\SecurityEventType;
use App\Models\Notification;
use App\Models\ObservabilityIncident;
use App\Models\User;
use App\Services\Observability\Detection\DetectionRuleEngine;
use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->recorder = app(SecurityEventRecorder::class);
    $this->engine = app(DetectionRuleEngine::class);
});

function seedFailedLogins(SecurityEventRecorder $recorder, string $ip, int $count, string $email = 'victim@example.com'): void
{
    for ($i = 0; $i < $count; $i++) {
        $recorder->record(
            SecurityEvent::of(SecurityEventType::AuthLoginFailed)
                ->source($ip)
                ->actor('guest', null, $email)
                ->summary('Failed login'),
        );
    }
}

it('opens an incident when failed logins from one IP cross the threshold', function () {
    seedFailedLogins($this->recorder, '198.51.100.77', 9);

    $result = $this->engine->run();

    expect($result['incidents_opened'])->toBe(1);

    $incident = ObservabilityIncident::query()->where('incident_key', 'like', 'detect:failed_login_burst:%')->first();

    expect($incident)->not->toBeNull()
        ->and($incident->status)->toBe('open')
        ->and($incident->severity)->toBe('high')
        ->and($incident->events()->count())->toBe(9);
});

it('does not open an incident below the threshold', function () {
    seedFailedLogins($this->recorder, '198.51.100.78', 3);

    $result = $this->engine->run();

    expect($result['incidents_opened'])->toBe(0)
        ->and(ObservabilityIncident::query()->count())->toBe(0);
});

it('does not duplicate an incident across repeated runs', function () {
    seedFailedLogins($this->recorder, '198.51.100.79', 10);

    $this->engine->run();
    $this->engine->run();

    expect(ObservabilityIncident::query()->count())->toBe(1);
});

it('escalates to critical when one IP attacks many accounts', function () {
    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i => $name) {
        seedFailedLogins($this->recorder, '203.0.113.90', 2, $name.'@example.com');
    }

    $this->engine->run();

    $incident = ObservabilityIncident::query()->where('incident_key', 'like', 'detect:failed_login_burst:%')->first();

    expect($incident->severity)->toBe('critical');
});

it('opens an incident for repeated webhook signature failures', function () {
    for ($i = 0; $i < 4; $i++) {
        $this->recorder->record(
            SecurityEvent::of(SecurityEventType::PaymentWebhookSignatureFailed)
                ->source('198.51.100.80')
                ->summary('Bad signature'),
        );
    }

    $this->engine->run();

    expect(ObservabilityIncident::query()->where('incident_key', 'like', 'detect:webhook_signature_failure:%')->exists())->toBeTrue();
});

it('notifies admins when a high-severity incident opens', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    seedFailedLogins($this->recorder, '198.51.100.81', 9);
    $this->engine->run();

    expect(Notification::query()->where('user_id', $admin->id)->where('type', 'security_incident')->exists())->toBeTrue();
});
