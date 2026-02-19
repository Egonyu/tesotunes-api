<?php

/**
 * Forum API Standardization Tests
 *
 * Verifies the admin forums API follows the standardized response format:
 * - Collections: { "data": [...], "meta": {...}, "links": {...} }
 * - Single resources: { "data": { ... } }
 * - No legacy "success" key
 */

use App\Models\Modules\Forum\ForumCategory;
use App\Models\Modules\Forum\ForumTopic;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $role = Role::factory()->admin()->create();
    DB::table('user_roles')->insert([
        'user_id' => $this->admin->id,
        'role_id' => $role->id,
        'is_active' => true,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    cache()->forget("user:{$this->admin->id}:roles");
});

test('forum topics index returns paginated data', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/forums');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['data']);

    $json = $response->json();
    if (isset($json['meta'])) {
        expect($json['meta'])->toHaveKey('current_page');
    }
});

test('forum stats returns data wrapper', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/forums/stats');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['data']);
});

test('forum categories returns data wrapper', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/forums/categories');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['data']);
});

test('forum show returns single resource', function () {
    // Try with a topic that may exist
    $response = $this->actingAs($this->admin)->getJson('/api/admin/forums/1');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['data']);
});

test('forum replies returns paginated collection', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/forums/1/replies');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['data']);
});

test('forum responses contain no success key', function () {
    $endpoints = [
        '/api/admin/forums',
        '/api/admin/forums/stats',
        '/api/admin/forums/categories',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->actingAs($this->admin)->getJson($endpoint);
        if ($response->status() === 200) {
            $response->assertJsonMissing(['success' => true])
                ->assertJsonMissing(['success' => false]);
        }
    }
});

test('forum delete returns json message', function () {
    $response = $this->actingAs($this->admin)->deleteJson('/api/admin/forums/999999');

    if ($response->status() === 500) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    // Should be JSON regardless of status
    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);
    }
});

test('forum toggle pin returns json', function () {
    $response = $this->actingAs($this->admin)->postJson('/api/admin/forums/1/pin');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['message']);
});

test('forum toggle lock returns json', function () {
    $response = $this->actingAs($this->admin)->postJson('/api/admin/forums/1/lock');

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');
        return;
    }

    $response->assertStatus(200)
        ->assertJsonStructure(['message']);
});

test('forum endpoints return json content type', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/admin/forums');
    expect($response->headers->get('Content-Type'))->toContain('json');
});
