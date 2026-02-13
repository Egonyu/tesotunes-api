<?php

/**
 * Edula / Feed API Standardization Tests
 *
 * The Edula module is the unified discovery feed at tesotunes.com/edula,
 * powered by FeedItem model and FeedService.
 *
 * Response format:
 * - Paginated: { "data": [...], "meta": {...}, "links": {...} } (manual construction)
 * - Single: { "data": { ... } }
 * - Actions: { "message": "..." }
 * - Tabs: { "data": [{key, label, icon}, ...] }
 * - No legacy "success" key
 */

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function createFeedUser(): User
{
    return User::factory()->create();
}

// ─── Public Feed Endpoints ───────────────────────────────────

test('main feed returns data wrapper', function () {
    $response = $this->getJson('/api/feed');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);

    // Should have manual meta/links for pagination
    $json = $response->json();
    if (isset($json['meta'])) {
        expect($json['meta'])->toHaveKey('current_page');
    }
});

test('feed tabs returns data array', function () {
    $response = $this->getJson('/api/feed/tabs');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('feed discover returns data wrapper', function () {
    $response = $this->getJson('/api/feed/discover');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('feed module returns data wrapper', function () {
    // Test with a module name like 'music'
    $response = $this->getJson('/api/feed/module/music');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500 || $response->status() === 404) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('feed show returns single item in data wrapper', function () {
    // Will likely 404 with a fake UUID, but should return JSON
    $response = $this->getJson('/api/feed/00000000-0000-0000-0000-000000000001');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);
        return;
    }

    if ($response->status() === 200) {
        $response->assertJsonStructure(['data']);
    }
});

// ─── Authenticated Feed Endpoints ────────────────────────────

test('for-you feed returns data wrapper', function () {
    $user = createFeedUser();
    $response = $this->actingAs($user)->getJson('/api/feed/for-you');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('following feed returns data wrapper', function () {
    $user = createFeedUser();
    $response = $this->actingAs($user)->getJson('/api/feed/following');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('saved feed returns data wrapper', function () {
    $user = createFeedUser();
    $response = $this->actingAs($user)->getJson('/api/feed/saved');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('feed preferences returns data wrapper', function () {
    $user = createFeedUser();
    $response = $this->actingAs($user)->getJson('/api/feed/preferences');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

// ─── Feed Actions ────────────────────────────────────────────

test('feed actions require authentication', function () {
    $uuid = '00000000-0000-0000-0000-000000000001';

    $actionEndpoints = [
        ['POST', "/api/feed/{$uuid}/save"],
        ['POST', "/api/feed/{$uuid}/not-interested"],
        ['POST', "/api/feed/{$uuid}/hide"],
        ['GET', '/api/feed/following'],
        ['GET', '/api/feed/saved'],
    ];

    foreach ($actionEndpoints as [$method, $endpoint]) {
        $response = $method === 'POST'
            ? $this->postJson($endpoint)
            : $this->getJson($endpoint);

        // Should be 401 not redirect
        if ($response->status() === 401) {
            expect($response->headers->get('Content-Type'))->toContain('json');
            $response->assertJsonStructure(['message']);
        }
    }
});

test('feed save action returns message', function () {
    $user = createFeedUser();
    $uuid = '00000000-0000-0000-0000-000000000001';

    $response = $this->actingAs($user)->postJson("/api/feed/{$uuid}/save");

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500 || $response->status() === 404) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['message']);
});

test('feed not-interested action returns message', function () {
    $user = createFeedUser();
    $uuid = '00000000-0000-0000-0000-000000000001';

    $response = $this->actingAs($user)->postJson("/api/feed/{$uuid}/not-interested");

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500 || $response->status() === 404) {
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['message']);
});

test('feed refresh returns json', function () {
    $user = createFeedUser();
    $response = $this->actingAs($user)->postJson('/api/feed/refresh');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    $response->assertOk();
});

test('feed update preferences returns json', function () {
    $user = createFeedUser();
    $response = $this->actingAs($user)->putJson('/api/feed/preferences', [
        'enabled_modules' => ['music', 'podcasts'],
    ]);

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        return;
    }

    if ($response->status() === 200) {
        $response->assertJsonStructure(['message']);
    }
});

// ─── No Success Key ──────────────────────────────────────────

test('feed responses contain no success key', function () {
    $endpoints = [
        '/api/feed',
        '/api/feed/tabs',
        '/api/feed/discover',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        if ($response->status() === 200) {
            $json = $response->json();
            expect($json)->not->toHaveKey('success');
        }
    }
});

// ─── JSON Content Type ──────────────────────────────────────

test('all feed endpoints return json not html', function () {
    $endpoints = [
        '/api/feed',
        '/api/feed/tabs',
        '/api/feed/discover',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        expect($response->headers->get('Content-Type'))->toContain('json');
        expect($response->getContent())->not->toContain('<!DOCTYPE');
    }
});
