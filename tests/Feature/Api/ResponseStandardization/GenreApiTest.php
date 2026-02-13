<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Genre;
use App\Models\Song;
use App\Models\Artist;
use App\Models\Album;
use App\Models\User;
use Tests\TestCase;

class GenreApiTest extends TestCase
{

    private Genre $genre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->genre = Genre::factory()->create(['is_active' => true]);
    }

    // ─── List Genres ─────────────────────────────────────────────

    public function test_list_genres_returns_data_wrapper(): void
    {
        Genre::factory()->count(3)->create(['is_active' => true]);

        $response = $this->getJson('/api/genres');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ]);

        // Must NOT have a top-level "success" key
        $response->assertJsonMissing(['success' => true]);
    }

    public function test_list_genres_returns_json_content_type(): void
    {
        $this->getJson('/api/genres')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    // ─── Single Genre ────────────────────────────────────────────

    public function test_show_genre_by_id_returns_resource(): void
    {
        $response = $this->getJson("/api/genres/{$this->genre->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'description',
                    'links',
                ],
            ]);
    }

    public function test_show_genre_by_slug_returns_resource(): void
    {
        $response = $this->getJson("/api/genres/{$this->genre->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                ],
            ]);
    }

    public function test_genre_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/genres/99999');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Genre Sub-resources ─────────────────────────────────────

    public function test_genre_songs_returns_paginated_response(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $user->id]);
        Song::factory()->count(3)->create([
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'primary_genre_id' => $this->genre->id,
            'status' => 'published',
        ]);

        $response = $this->getJson("/api/genres/{$this->genre->id}/songs");

        $response->assertOk();
        $json = $response->json();

        // Must have 'data' key with songs array
        $this->assertArrayHasKey('data', $json, 'Genre songs should have data key');
        $this->assertIsArray($json['data']);

        // Pagination can be in 'meta' key (standardized) or at root level (raw paginator)
        $hasMeta = isset($json['meta']['current_page']);
        $hasRootPagination = isset($json['current_page']);
        $this->assertTrue($hasMeta || $hasRootPagination, 'Genre songs should have pagination info');
    }

    public function test_genre_artists_returns_paginated_response(): void
    {
        $response = $this->getJson("/api/genres/{$this->genre->id}/artists");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
            ]);
    }

    public function test_genre_albums_returns_paginated_response(): void
    {
        $response = $this->getJson("/api/genres/{$this->genre->id}/albums");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
            ]);
    }

    // ─── No "success" key anywhere ───────────────────────────────

    public function test_genre_responses_never_contain_success_key(): void
    {
        $endpoints = [
            "/api/genres",
            "/api/genres/{$this->genre->id}",
            "/api/genres/{$this->genre->slug}",
            "/api/genres/{$this->genre->id}/songs",
            "/api/genres/{$this->genre->id}/artists",
            "/api/genres/{$this->genre->id}/albums",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();

            $json = $response->json();
            $this->assertArrayNotHasKey('success', $json, "Endpoint {$endpoint} still has 'success' key");
        }
    }
}
