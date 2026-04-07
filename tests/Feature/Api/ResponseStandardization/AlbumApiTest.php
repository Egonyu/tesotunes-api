<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Models\User;

class AlbumApiTest extends ResponseStandardizationTestCase
{
    private User $user;

    private Artist $artist;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->artist = Artist::factory()->create(['user_id' => $this->user->id]);
        $this->album = Album::factory()->create([
            'artist_id' => $this->artist->id,
            'status' => 'published',
        ]);
    }

    // ─── List Albums ─────────────────────────────────────────────

    public function test_list_albums_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/albums');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'slug'],
                ],
            ]);
    }

    public function test_list_albums_returns_pagination_meta(): void
    {
        Album::factory()->count(5)->create([
            'artist_id' => $this->artist->id,
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/albums');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);
    }

    // ─── Single Album ────────────────────────────────────────────

    public function test_show_album_by_slug_returns_resource(): void
    {
        $response = $this->getJson("/api/albums/{$this->album->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'slug',
                    'artist',
                    'links',
                ],
            ]);
    }

    public function test_show_album_by_id_returns_resource(): void
    {
        $response = $this->getJson("/api/albums/{$this->album->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'slug',
                ],
            ]);
    }

    public function test_album_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/albums/nonexistent-album-xyz');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Album Tracks ────────────────────────────────────────────

    public function test_album_tracks_returns_response(): void
    {
        Song::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'album_id' => $this->album->id,
            'status' => 'published',
            'duration_seconds' => 210,
            'audio_file_128' => 'songs/128/album-test.mp3',
        ]);

        $response = $this->getJson("/api/albums/{$this->album->id}/tracks");

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

    // ─── Response Format ─────────────────────────────────────────

    public function test_album_responses_contain_no_success_key(): void
    {
        $endpoints = [
            '/api/albums',
            "/api/albums/{$this->album->slug}",
            "/api/albums/{$this->album->id}",
            "/api/albums/{$this->album->id}/tracks",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $this->assertArrayNotHasKey('success', $response->json(), "Endpoint {$endpoint} still has 'success' key");
        }
    }
}
