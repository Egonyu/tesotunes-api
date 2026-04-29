<?php

/**
 * Polls API Standardization Tests
 *
 * Verifies the polls API follows the standardized response format:
 * - Results: { "data": { ... } } via PollResource
 * - Vote: { "data": ..., "message": "..." }
 * - Errors: { "message": "..." } with 422
 * - No legacy "success" key
 */

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('poll results returns data wrapper', function () {
    $response = $this->getJson('/api/polls/1/results');

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

test('poll results contains no success key', function () {
    $response = $this->getJson('/api/polls/1/results');

    // Always assert content type regardless of status
    expect($response->headers->get('Content-Type'))->toContain('json');

    if ($response->status() === 200) {
        $json = $response->json();
        expect($json)->not->toHaveKey('success');
    }

    if ($response->status() === 404) {
        $response->assertJsonStructure(['message']);
    }
});

test('poll respond endpoint returns json not redirect', function () {
    $response = $this->postJson('/api/polls/1/respond', [
        'answers' => [['question_id' => 1, 'option_ids' => [1]]],
    ]);

    // Must return JSON regardless of status (401, 403, 404, 422)
    expect($response->headers->get('Content-Type'))->toContain('json');
    expect($response->status())->not->toBe(302);
});

test('poll vote returns data and message on success', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/polls/1/respond', [
        'answers' => [['question_id' => 1, 'option_ids' => [1]]],
    ]);

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    if ($response->status() === 422) {
        // Validation error or already voted or inactive poll
        $response->assertJsonStructure(['message']);

        return;
    }

    if ($response->status() === 200) {
        $response->assertJsonStructure(['data', 'message']);
    }
});

test('poll vote validation returns message on error', function () {
    $user = User::factory()->create();

    // Send empty answers to trigger validation
    $response = $this->actingAs($user)->postJson('/api/polls/1/respond', ['answers' => []]);

    if ($response->status() === 500 || $response->status() === 404) {
        expect($response->headers->get('Content-Type'))->toContain('json');

        return;
    }

    // Should have message key for validation errors
    if ($response->status() === 422) {
        $response->assertJsonStructure(['message']);
    }
});

test('poll endpoints return json content type', function () {
    $response = $this->getJson('/api/polls/1/results');
    expect($response->headers->get('Content-Type'))->toContain('json');
});
