<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;

/**
 * Cross-cutting tests that verify ALL public endpoints follow the standardized
 * API response format:
 *
 * - Single resource: { "data": { ... } }
 * - Collection: { "data": [...], "meta": {...}, "links": {...} }
 * - Error: { "message": "..." }
 * - No "success" key ever
 * - Always JSON content type
 * - 404s return JSON, not HTML
 */
class ResponseFormatConsistencyTest extends ResponseStandardizationTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * All public GET endpoints must return JSON content type, never HTML.
     */
    public function test_all_public_endpoints_return_json_content_type(): void
    {
        $genre = Genre::factory()->create(['is_active' => true]);
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id, 'status' => 'active']);
        $album = Album::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);
        $song = Song::factory()->create([
            'user_id' => $artistUser->id,
            'artist_id' => $artist->id,
            'primary_genre_id' => $genre->id,
            'status' => 'published',
        ]);
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'visibility' => 'public',
        ]);

        $endpoints = [
            '/api/health',
            '/api/genres',
            "/api/genres/{$genre->id}",
            "/api/genres/{$genre->slug}",
            '/api/songs',
            "/api/songs/{$song->slug}",
            '/api/artists',
            "/api/artists/{$artist->slug}",
            '/api/albums',
            "/api/albums/{$album->slug}",
            '/api/playlists',
            '/api/playlists/featured',
            "/api/playlists/{$playlist->slug}",
            '/api/podcasts',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/json');
        }
    }

    /**
     * No endpoint should return the old { success: true, data: ... } format.
     */
    public function test_no_endpoint_returns_success_key(): void
    {
        $genre = Genre::factory()->create(['is_active' => true]);

        $publicEndpoints = [
            '/api/genres',
            "/api/genres/{$genre->id}",
            '/api/songs',
            '/api/artists',
            '/api/albums',
            '/api/playlists',
            '/api/playlists/featured',
            '/api/podcasts',
        ];

        foreach ($publicEndpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $json = $response->json();
            $this->assertArrayNotHasKey('success', $json, "Endpoint {$endpoint} still returns 'success' key");
        }
    }

    /**
     * 404 responses must be JSON with "message" key, not HTML error pages.
     */
    public function test_404_responses_are_json_not_html(): void
    {
        $endpoints = [
            '/api/genres/nonexistent-slug-xyz-99999',
            '/api/songs/nonexistent-slug-xyz-99999',
            '/api/artists/nonexistent-slug-xyz-99999',
            '/api/albums/nonexistent-slug-xyz-99999',
            '/api/playlists/nonexistent-slug-xyz-99999',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertNotFound()
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonStructure(['message']);

            // Ensure it's not returning HTML
            $content = $response->getContent();
            $this->assertStringNotContainsString('<!DOCTYPE', $content, "Endpoint {$endpoint} returned HTML instead of JSON");
            $this->assertStringNotContainsString('<html', $content, "Endpoint {$endpoint} returned HTML instead of JSON");
        }
    }

    /**
     * Collection endpoints must use "data" as the array key, not entity-specific keys.
     */
    public function test_collections_use_data_key_not_entity_names(): void
    {
        Genre::factory()->count(3)->create(['is_active' => true]);

        $response = $this->getJson('/api/genres');
        $json = $response->json();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayNotHasKey('genres', $json, "Should use 'data' not 'genres'");

        $response = $this->getJson('/api/songs');
        $json = $response->json();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayNotHasKey('songs', $json, "Should use 'data' not 'songs'");

        $response = $this->getJson('/api/artists');
        $json = $response->json();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayNotHasKey('artists', $json, "Should use 'data' not 'artists'");

        $response = $this->getJson('/api/albums');
        $json = $response->json();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayNotHasKey('albums', $json, "Should use 'data' not 'albums'");
    }

    /**
     * Paginated endpoints must use "meta" for pagination, not "pagination".
     */
    public function test_paginated_endpoints_use_meta_not_pagination(): void
    {
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);
        Song::factory()->count(5)->create([
            'user_id' => $artistUser->id,
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);

        $paginatedEndpoints = [
            '/api/songs',
            '/api/artists',
            '/api/albums',
            '/api/playlists',
            '/api/podcasts',
        ];

        foreach ($paginatedEndpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $json = $response->json();

            $this->assertArrayHasKey('meta', $json, "Endpoint {$endpoint} missing 'meta' key");
            $this->assertArrayNotHasKey('pagination', $json, "Endpoint {$endpoint} uses old 'pagination' key instead of 'meta'");

            // Meta must contain standard Laravel pagination fields
            $this->assertArrayHasKey('current_page', $json['meta'], "Endpoint {$endpoint} meta missing 'current_page'");
            $this->assertArrayHasKey('per_page', $json['meta'], "Endpoint {$endpoint} meta missing 'per_page'");
            $this->assertArrayHasKey('total', $json['meta'], "Endpoint {$endpoint} meta missing 'total'");
        }
    }

    /**
     * Authenticated endpoints must return 401 JSON (not redirect) when unauthenticated.
     */
    public function test_auth_endpoints_return_401_json_not_redirect(): void
    {
        $authEndpoints = [
            ['GET', '/api/user/profile'],
            ['GET', '/api/user/library'],
            ['GET', '/api/notifications'],
            ['POST', '/api/player/record-play'],
        ];

        foreach ($authEndpoints as [$method, $endpoint]) {
            $response = $method === 'GET'
                ? $this->getJson($endpoint)
                : $this->postJson($endpoint, []);

            $response->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonStructure(['message']);

            // Must NOT redirect to a login page (old Blade behavior)
            $this->assertNotEquals(302, $response->status(), "Endpoint {$endpoint} redirects instead of returning 401 JSON");
        }
    }

    /**
     * Validation errors must use standard Laravel format: { message, errors }.
     */
    public function test_validation_errors_use_standard_format(): void
    {
        // Register with invalid data
        $response = $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors',
            ]);

        $json = $response->json();
        $this->assertArrayNotHasKey('success', $json, 'Validation error should not have success key');
    }

    /**
     * Resources must include ISO 8601 timestamps when they have created_at.
     */
    public function test_resources_use_iso8601_timestamps(): void
    {
        $genre = Genre::factory()->create(['is_active' => true]);

        $response = $this->getJson("/api/genres/{$genre->id}");
        $response->assertOk();

        $data = $response->json('data');

        if (isset($data['created_at'])) {
            // ISO 8601 contains 'T' separator and timezone
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
                $data['created_at'],
                'Timestamps should be ISO 8601 format'
            );
        }
    }

    /**
     * Single resources must be wrapped in { data: { ... } }, not bare.
     */
    public function test_single_resources_wrapped_in_data_key(): void
    {
        $genre = Genre::factory()->create(['is_active' => true]);

        $response = $this->getJson("/api/genres/{$genre->id}");
        $response->assertOk();

        $json = $response->json();
        $this->assertArrayHasKey('data', $json, 'Single resource must be wrapped in data key');
        $this->assertIsArray($json['data']);
        $this->assertArrayHasKey('id', $json['data'], 'Resource data must contain id');

        // The id should NOT be at the top level
        $this->assertArrayNotHasKey('id', $json, 'Resource id should not be at top level — must be inside data');
    }
}
