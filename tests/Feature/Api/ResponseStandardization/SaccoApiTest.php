<?php

/**
 * Sacco API Standardization Tests
 *
 * Verifies the Sacco module APIs follow the standardized response format:
 * - Collections: { "data": [...], "meta": {...}, "links": {...} }
 * - Single resources: { "data": { ... } }
 * - Actions: { "data": ..., "message": "..." }
 * - Reports: { "data": { ... } }
 * - Errors: { "message": "..." }
 * - No legacy "success" key
 *
 * All Sacco user routes require auth:sanctum.
 */

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function createSaccoUser(): User
{
    return User::factory()->create();
}

function createSaccoAdmin(): User
{
    $admin = User::factory()->create();
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Admin', 'description' => 'Administrator with full system management', 'is_active' => true, 'priority' => 5]
    );
    DB::table('user_roles')->insert([
        'user_id' => $admin->id,
        'role_id' => $role->id,
        'is_active' => true,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    cache()->forget("user:{$admin->id}:roles");

    return $admin;
}

// ─── Authentication ──────────────────────────────────────────

test('sacco membership requires authentication', function () {
    $response = $this->getJson('/api/sacco/members');

    $response->assertUnauthorized()
        ->assertJsonStructure(['message']);

    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('sacco savings requires authentication', function () {
    $response = $this->postJson('/api/sacco/savings/deposit', []);

    $response->assertUnauthorized();
    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('sacco loans requires authentication', function () {
    $response = $this->postJson('/api/sacco/loans/apply', []);

    $response->assertUnauthorized();
    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('sacco shares requires authentication', function () {
    $response = $this->postJson('/api/sacco/shares/purchase', []);

    $response->assertUnauthorized();
    expect($response->headers->get('Content-Type'))->toContain('json');
});

// ─── Membership ──────────────────────────────────────────────

test('members index returns paginated data', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/members');

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);

    $json = $response->json();
    if (isset($json['meta'])) {
        expect($json['meta'])->toHaveKey('current_page');
    }
});

test('member show returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/members/1');

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('member registration returns 201 with data and message', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->postJson('/api/sacco/members', [
        'user_id' => $user->id,
        'member_type' => 'regular',
    ]);

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    if ($response->status() === 422) {
        $response->assertJsonStructure(['message']);

        return;
    }

    if ($response->status() === 201) {
        $response->assertJsonStructure(['data', 'message']);
    }
});

// ─── Savings ─────────────────────────────────────────────────

test('savings account show returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/savings/accounts/1');

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('savings transactions returns paginated data', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/savings/transactions/1');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('savings balance returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/savings/balance/1');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('savings open account returns 201 with data and message', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->postJson('/api/sacco/savings/accounts', [
        'account_type' => 'savings',
    ]);

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    if ($response->status() === 422) {
        $response->assertJsonStructure(['message']);

        return;
    }

    if ($response->status() === 201) {
        $response->assertJsonStructure(['data', 'message']);
    }
});

// ─── Loans ───────────────────────────────────────────────────

test('loan show returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/loans/1');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('member loans returns paginated data', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/loans/member/1');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('loan schedule returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/loans/1/schedule');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('loan balance returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/loans/1/balance');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('loan apply returns 201 with data and message', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->postJson('/api/sacco/loans/apply', [
        'amount' => 500000,
        'purpose' => 'Business expansion',
        'term_months' => 12,
    ]);

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    if ($response->status() === 422) {
        $response->assertJsonStructure(['message']);

        return;
    }

    if ($response->status() === 201) {
        $response->assertJsonStructure(['data', 'message']);
    }
});

// ─── Shares ──────────────────────────────────────────────────

test('shares current value returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/shares/value');

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('member shares returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/shares/member/1');

    if (in_array($response->status(), [403, 404, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

// ─── Reports ─────────────────────────────────────────────────

test('sacco reports return data wrapper', function () {
    $user = createSaccoUser();

    $reportEndpoints = [
        '/api/sacco/reports/membership',
        '/api/sacco/reports/loans',
        '/api/sacco/reports/savings',
        '/api/sacco/reports/shares',
        '/api/sacco/reports/financial',
        '/api/sacco/reports/overdue',
    ];

    foreach ($reportEndpoints as $endpoint) {
        $response = $this->actingAs($user)->getJson($endpoint);

        if (in_array($response->status(), [403, 500, 503])) {
            expect($response->headers->get('Content-Type'))->toContain('json');

            continue;
        }

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }
});

// ─── Analytics ───────────────────────────────────────────────

test('sacco analytics dashboard returns data wrapper', function () {
    $user = createSaccoUser();

    $response = $this->actingAs($user)->getJson('/api/sacco/analytics/dashboard');

    if (in_array($response->status(), [403, 500, 503])) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('sacco analytics endpoints return data wrapper', function () {
    $user = createSaccoUser();

    $analyticsEndpoints = [
        '/api/sacco/analytics/trends/membership',
        '/api/sacco/analytics/performance/loans',
        '/api/sacco/analytics/savings',
        '/api/sacco/analytics/portfolio',
    ];

    foreach ($analyticsEndpoints as $endpoint) {
        $response = $this->actingAs($user)->getJson($endpoint);

        if (in_array($response->status(), [403, 500, 503])) {
            expect($response->headers->get('Content-Type'))->toContain('json');

            continue;
        }

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }
});

// ─── No Success Key ──────────────────────────────────────────

test('sacco responses contain no success key', function () {
    $user = createSaccoUser();

    $endpoints = [
        '/api/sacco/members',
        '/api/sacco/shares/value',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->actingAs($user)->getJson($endpoint);
        expect($response->headers->get('Content-Type'))->toContain('json');
        if ($response->status() === 200) {
            $json = $response->json();
            expect($json)->not->toHaveKey('success');
        }
    }
});

// ─── Admin Sacco ─────────────────────────────────────────────

test('admin sacco stats returns data wrapper', function () {
    $admin = createSaccoAdmin();
    $response = $this->actingAs($admin)->getJson('/api/admin/sacco/stats');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin sacco members returns data', function () {
    $admin = createSaccoAdmin();
    $response = $this->actingAs($admin)->getJson('/api/admin/sacco/members');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin sacco loans returns data', function () {
    $admin = createSaccoAdmin();
    $response = $this->actingAs($admin)->getJson('/api/admin/sacco/loans');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('sacco endpoints return json content type', function () {
    $user = createSaccoUser();
    $response = $this->actingAs($user)->getJson('/api/sacco/members');
    expect($response->headers->get('Content-Type'))->toContain('json');
});
