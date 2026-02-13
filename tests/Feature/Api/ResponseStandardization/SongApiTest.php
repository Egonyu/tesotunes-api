<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Song;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\User;
use Tests\TestCase;

class SongApiTest extends TestCase
{

    private User $user;
    private Artist $artist;
    private Song $song;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->artist = Artist::factory()->create(['user_id' => $this->user->id]);
        $this->song = Song::factory()->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
        ]);
    }

    // ─── List Songs ──────────────────────────────────────────────

    public function test_list_songs_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/songs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'slug'],
                ],
            ]);
    }

    public function test_list_songs_returns_pagination_meta(): void
    {
        Song::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
        ]);

        $response = $this->getJson('/api/songs');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);
    }

    // ─── Single Song ─────────────────────────────────────────────

    public function test_show_song_returns_resource(): void
    {
        $response = $this->getJson("/api/songs/{$this->song->slug}");

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

    public function test_song_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/songs/nonexistent-slug-xyz');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Response Format ─────────────────────────────────────────

    public function test_song_responses_contain_no_success_key(): void
    {
        $endpoints = [
            '/api/songs',
            "/api/songs/{$this->song->slug}",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();

            $json = $response->json();
            $this->assertArrayNotHasKey('success', $json, "Endpoint {$endpoint} still has 'success' key");
        }
    }

    public function test_song_resource_includes_artist_inline(): void
    {
        $response = $this->getJson("/api/songs/{$this->song->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'artist' => ['id', 'name'],
                ],
            ]);
    }

    public function test_songs_return_json_content_type(): void
    {
        $this->getJson('/api/songs')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }
}
