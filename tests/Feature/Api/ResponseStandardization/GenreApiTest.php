<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Models\User;

class GenreApiTest extends ResponseStandardizationTestCase
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
        $songs = Song::factory()->count(3)->create([
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'status' => 'published',
            'duration_seconds' => 195,
            'audio_file_128' => 'songs/128/genre-test.mp3',
        ]);

        foreach ($songs as $song) {
            $song->genres()->syncWithoutDetaching([$this->genre->id]);
        }

        $response = $this->getJson("/api/genres/{$this->genre->id}/songs");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'title',
                    'slug',
                    'duration_seconds',
                    'duration_formatted',
                    'audio_url',
                    'stream_url',
                    'preview_url',
                    'artwork_url',
                    'artist',
                    'links',
                ]],
                'meta' => ['current_page', 'per_page', 'total'],
                'links',
            ])
            ->assertJsonMissing(['success' => true]);
    }

    public function test_genre_artists_returns_paginated_response(): void
    {
        $response = $this->getJson("/api/genres/{$this->genre->id}/artists");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total'],
                'links',
            ])
            ->assertJsonMissing(['success' => true]);
    }

    public function test_genre_albums_returns_paginated_response(): void
    {
        $response = $this->getJson("/api/genres/{$this->genre->id}/albums");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total'],
                'links',
            ])
            ->assertJsonMissing(['success' => true]);
    }

    // ─── No "success" key anywhere ───────────────────────────────

    public function test_genre_responses_never_contain_success_key(): void
    {
        $endpoints = [
            '/api/genres',
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
