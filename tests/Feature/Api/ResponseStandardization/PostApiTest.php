<?php

/**
 * Posts API response contract tests.
 *
 * Verifies the legacy paginator shape remains stable for GET /api/posts.
 */

use App\Models\Artist;
use App\Models\Post;
use App\Models\Song;
use App\Models\User;
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

test('post detail uses canonical artwork url for linked song media', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);
    $song = Song::factory()->create([
        'user_id' => $user->id,
        'artist_id' => $artist->id,
        'status' => 'published',
        'visibility' => 'public',
        'artwork' => 'songs/artwork/test-linked-song.jpg',
    ]);
    $post = Post::create([
        'user_id' => $user->id,
        'song_id' => $song->id,
        'content' => 'Linked song post',
        'type' => 'music',
        'visibility' => 'public',
        'privacy' => 'public',
        'published_at' => now(),
    ]);

    $response = $this->json('GET', "/api/posts/{$post->id}");

    $response->assertOk();
    expect($response->json('data.media.type'))->toBe('song');
    expect($response->json('data.media.url'))->toBe($song->artwork_url);
    expect($response->json('data.media.thumbnail_url'))->toBe($song->artwork_url);
});
