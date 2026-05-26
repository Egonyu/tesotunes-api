<?php

use App\Models\ObservabilityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('records a failed-login security event for invalid credentials', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'ghost@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);

    $event = ObservabilityEvent::query()->where('domain', 'auth')->where('category', 'login')->first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe('failed')
        ->and($event->details['reason'])->toBe('invalid_credentials');
});

it('records a successful-login security event', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3r-Secret!9'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'Sup3r-Secret!9',
    ])->assertOk();

    $event = ObservabilityEvent::query()
        ->where('domain', 'auth')
        ->where('category', 'login')
        ->where('outcome', 'success')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_id)->toBe((string) $user->id);
});

it('records a registration security event', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'New Listener',
        'email' => 'newlistener@example.com',
        'password' => 'Sup3r-Secret!9',
        'password_confirmation' => 'Sup3r-Secret!9',
    ])->assertCreated();

    $event = ObservabilityEvent::query()->where('domain', 'auth')->where('category', 'registration')->first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe('success');
});
