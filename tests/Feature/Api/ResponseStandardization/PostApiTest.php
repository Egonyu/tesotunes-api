<?php

/**
 * Posts API response contract tests.
 *
 * Verifies the legacy paginator shape remains stable for GET /api/posts.
 */

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('posts index returns legacy paginator contract', function () {
    $response = $this->json('GET', '/api/posts');

    expect($response->headers->get('Content-Type'))->toContain('json');

    $response->assertOk()
        ->assertJsonStructure([
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total',
        ]);

    $json = $response->json();

    expect((string) ($json['path'] ?? ''))->toContain('/api/posts');
    expect((string) ($json['first_page_url'] ?? ''))->toContain('/api/posts');
    if (($json['next_page_url'] ?? null) !== null) {
        expect((string) $json['next_page_url'])->toContain('/api/posts');
    }

    expect($json)->not->toHaveKey('success');
});

test('posts index respects per_page query parameter', function () {
    $response = $this->json('GET', '/api/posts?per_page=5');

    expect($response->headers->get('Content-Type'))->toContain('json');

    $response->assertOk();
    expect((int) $response->json('per_page'))->toBe(5);
});
