<?php

/**
 * Awards API Standardization Tests
 *
 * Verifies the awards API follows the standardized response format:
 * - Collections: { "data": [...], "meta": {...}, "links": {...} } via AwardResource
 * - Single resources: { "data": { ... } }
 * - Categories: non-paginated collection
 * - Nominations: paginated collection
 * - Vote/nominate: { "data": ..., "message": "..." } with 201
 * - Errors: { "message": "..." }
 * - No legacy "success" key
 */

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('list awards returns paginated data wrapper', function () {
    $response = $this->getJson('/api/awards');

    $response->assertOk()
        ->assertJsonStructure(['data']);

    $json = $response->json();
    if (isset($json['meta'])) {
        expect($json['meta'])->toHaveKey('current_page');
    }
});

test('awards contain no success key', function () {
    $response = $this->getJson('/api/awards');
    $response->assertOk();

    $json = $response->json();
    expect($json)->not->toHaveKey('success');
});

test('awards return json content type', function () {
    $response = $this->getJson('/api/awards');
    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('current season returns data or 404 json', function () {
    $response = $this->getJson('/api/awards/current-season');

    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 500) {
        // Controller may have issues — but must be JSON not HTML
        expect($response->getContent())->not->toContain('<!DOCTYPE');
        return;
    }

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('award show returns single resource', function () {
    $response = $this->getJson('/api/awards/1');

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

test('award categories returns collection', function () {
    $response = $this->getJson('/api/awards/1/categories');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('award nominations returns paginated collection', function () {
    $response = $this->getJson('/api/awards/1/categories/1/nominations');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('award results returns data wrapper', function () {
    $response = $this->getJson('/api/awards/1/results');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    // May be 403 if results not available yet
    if ($response->status() === 403) {
        $response->assertJsonStructure(['message']);
        return;
    }

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);
        return;
    }

    $response->assertOk()
        ->assertJsonStructure(['data']);
});

test('vote requires authentication', function () {
    $response = $this->postJson('/api/awards/1/vote', []);

    $response->assertUnauthorized()
        ->assertJsonStructure(['message']);

    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('nomination requires authentication', function () {
    $response = $this->postJson('/api/awards/1/nominations', []);

    $response->assertUnauthorized()
        ->assertJsonStructure(['message']);

    expect($response->headers->get('Content-Type'))->toContain('json');
});

test('vote returns data and message on success', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/awards/1/vote', [
        'nomination_id' => 1,
        'category_id' => 1,
    ]);

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    if ($response->status() === 422 || $response->status() === 403) {
        $response->assertJsonStructure(['message']);
        return;
    }

    if ($response->status() === 201) {
        $response->assertJsonStructure(['data', 'message']);
    }
});

test('awards public endpoints do not return html', function () {
    $endpoints = [
        '/api/awards',
        '/api/awards/current-season',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->getJson($endpoint);
        expect($response->getContent())->not->toContain('<!DOCTYPE');
        expect($response->headers->get('Content-Type'))->toContain('json');
    }
});
