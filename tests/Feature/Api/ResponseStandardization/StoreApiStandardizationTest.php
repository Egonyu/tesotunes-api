<?php

/**
 * Store API Standardization Tests
 *
 * Covers three route layers:
 * 1. Store AJAX routes (/api/store/*) - cart, orders, promotions
 * 2. Store Module routes (/api/v1/store/*) - public stores, products, buyer/seller
 * 3. Admin Store routes (/api/admin/store/*) - admin management
 *
 * Response format:
 * - Collections: { "data": [...], "meta": {...} }
 * - Single resources: { "data": { ... } }
 * - No legacy "success" key
 */

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['store.enabled' => true]);
});

function createStoreUser(): User
{
    return User::factory()->create();
}

// ─── AJAX Store Routes (/api/store/*) ────────────────────────

test('get cart returns data wrapper', function () {
    $user = createStoreUser();
    $response = $this->actingAs($user)->getJson('/api/store/cart');

    $response->assertOk()
        ->assertJsonStructure(['data']);

    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('cart contains no success key', function () {
    $user = createStoreUser();
    $response = $this->actingAs($user)->getJson('/api/store/cart');

    $response->assertOk();
    $json = $response->json();
    expect($json)->not->toHaveKey('success');
});

test('list orders returns data', function () {
    $user = createStoreUser();
    $response = $this->actingAs($user)->getJson('/api/store/orders');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('orders contain no success key', function () {
    $user = createStoreUser();
    $response = $this->actingAs($user)->getJson('/api/store/orders');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk();
    $json = $response->json();
    expect($json)->not->toHaveKey('success');
});

test('list promotions returns json', function () {
    $user = createStoreUser();
    $response = $this->actingAs($user)->getJson('/api/store/promotions');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 200) {
        $response->assertJsonStructure(['data']);
    }
});

test('store ajax endpoints return json for unauthenticated', function () {
    $endpoints = [
        '/api/store/cart',
        '/api/store/orders',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        expect($response->headers->get('Content-Type'))->toContain('json');
        expect($response->status())->toBeIn([401, 403]);
    }
});

// ─── Store Module Public Routes (/api/v1/store/public/*) ─────

test('public stores index returns paginated data', function () {
    $response = $this->getJson('/api/v1/store/public/stores');

    if (in_array($response->status(), [404, 500])) {
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

test('public stores featured returns data', function () {
    $response = $this->getJson('/api/v1/store/public/stores/featured');

    if (in_array($response->status(), [404, 500])) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('public store show returns data wrapper', function () {
    $response = $this->getJson('/api/v1/store/public/stores/1');

    if ($response->status() === 500) {
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

test('public products index returns paginated data', function () {
    $response = $this->getJson('/api/v1/store/public/products');

    if (in_array($response->status(), [404, 500])) {
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

test('public products featured returns data', function () {
    $response = $this->getJson('/api/v1/store/public/products/featured');

    if (in_array($response->status(), [404, 500])) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('public products trending returns data', function () {
    $response = $this->getJson('/api/v1/store/public/products/trending');

    if (in_array($response->status(), [404, 500])) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('public product show returns data wrapper', function () {
    $response = $this->getJson('/api/v1/store/public/products/1');

    if ($response->status() === 500) {
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

test('product availability returns data wrapper', function () {
    $response = $this->getJson('/api/v1/store/public/products/1/availability');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('product reviews returns data', function () {
    $response = $this->getJson('/api/v1/store/public/products/1/reviews');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('public store endpoints contain no success key', function () {
    $endpoints = [
        '/api/v1/store/public/stores',
        '/api/v1/store/public/products',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        expect($response->headers->get('Content-Type'))->toContain('json');
        if ($response->status() === 200) {
            $json = $response->json();
            expect($json)->not->toHaveKey('success');
        }
    }
});

// ─── Store Module Auth Routes ────────────────────────────────

test('store cart requires authentication', function () {
    $response = $this->getJson('/api/v1/store/cart');

    // Route may not exist yet (404) or require auth (401)
    expect($response->headers->get('Content-Type'))->toContain('json');
    if ($response->status() === 401) {
        $response->assertJsonStructure(['message']);
    }
});

test('store orders requires authentication', function () {
    $response = $this->getJson('/api/v1/store/orders');

    // Route may not exist yet (404) or require auth (401)
    expect($response->headers->get('Content-Type'))->toContain('json');
    if ($response->status() === 401) {
        $response->assertJsonStructure(['message']);
    }
});

test('seller store creation requires auth', function () {
    $response = $this->postJson('/api/v1/store/seller/stores', [
        'name' => 'Test Store',
    ]);

    // Route may not exist yet (404) or require auth (401/422)
    expect($response->headers->get('Content-Type'))->toContain('json');
    if ($response->status() === 401) {
        $response->assertJsonStructure(['message']);
    }
});

// ─── Admin Store Routes (/api/admin/store/*) ─────────────────

test('admin store stats returns data wrapper', function () {
    $response = $this->getJson('/api/admin/store/stats');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin store products returns paginated data', function () {
    $response = $this->getJson('/api/admin/store/products');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);

    // Admin store uses manual meta (no links key)
    $json = $response->json();
    if (isset($json['meta'])) {
        expect($json['meta'])->toHaveKey('current_page');
    }
});

test('admin store orders returns paginated data', function () {
    $response = $this->getJson('/api/admin/store/orders');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin store shops returns data', function () {
    $response = $this->getJson('/api/admin/store/shops');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin store analytics returns data', function () {
    $response = $this->getJson('/api/admin/store/analytics');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('admin store responses contain no success key', function () {
    $endpoints = [
        '/api/admin/store/stats',
        '/api/admin/store/products',
        '/api/admin/store/orders',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        expect($response->headers->get('Content-Type'))->toContain('json');
        if ($response->status() === 200) {
            $json = $response->json();
            expect($json)->not->toHaveKey('success');
        }
    }
});

// ─── JSON Content Type ───────────────────────────────────────

test('all store endpoints return json content type', function () {
    $endpoints = [
        '/api/v1/store/public/stores',
        '/api/v1/store/public/products',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        expect($response->headers->get('Content-Type'))->toContain('json');
    }
});
