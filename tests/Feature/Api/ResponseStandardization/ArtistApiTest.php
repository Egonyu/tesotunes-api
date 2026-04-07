<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Models\User;

class ArtistApiTest extends ResponseStandardizationTestCase
{
    private User $user;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->artist = Artist::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    // ─── List Artists ────────────────────────────────────────────

    public function test_list_artists_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/artists');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ]);
    }

    public function test_list_artists_returns_pagination_meta(): void
    {
        Artist::factory()->count(5)->create();

        $response = $this->getJson('/api/artists');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);
    }

    // ─── Single Artist ───────────────────────────────────────────

    public function test_show_artist_returns_resource(): void
    {
        $response = $this->getJson("/api/artists/{$this->artist->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'bio',
                    'is_verified',
                    'links',
                ],
            ]);
    }

    public function test_artist_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/artists/nonexistent-artist-xyz');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Artist Sub-resources ────────────────────────────────────

    public function test_artist_songs_returns_paginated_response(): void
    {
        Song::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'duration_seconds' => 180,
            'audio_file_128' => 'songs/128/artist-test.mp3',
        ]);

        $response = $this->getJson("/api/artists/{$this->artist->id}/songs");

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

    public function test_artist_albums_returns_paginated_response(): void
    {
        Album::factory()->count(2)->create([
            'artist_id' => $this->artist->id,
            'status' => 'published',
        ]);

        $response = $this->getJson("/api/artists/{$this->artist->id}/albums");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'per_page', 'total'],
                'links',
            ]);
    }

    // ─── Response Format ─────────────────────────────────────────

    public function test_artist_responses_contain_no_success_key(): void
    {
        $endpoints = [
            '/api/artists',
            "/api/artists/{$this->artist->slug}",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $this->assertArrayNotHasKey('success', $response->json(), "Endpoint {$endpoint} still has 'success' key");
        }
    }

    public function test_artists_return_json_content_type(): void
    {
        $this->getJson('/api/artists')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }
}
