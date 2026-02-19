<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Playlist;
use App\Models\User;
use Tests\TestCase;

class PlaylistApiTest extends TestCase
{
    private User $user;

    private Playlist $playlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'visibility' => 'public',
            'is_featured' => true,
        ]);
    }

    // ─── List Playlists ──────────────────────────────────────────

    public function test_list_playlists_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/playlists');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ]);
    }

    public function test_list_playlists_returns_pagination_meta(): void
    {
        Playlist::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'visibility' => 'public',
        ]);

        $response = $this->getJson('/api/playlists');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);
    }

    // ─── Featured Playlists ──────────────────────────────────────

    public function test_featured_playlists_returns_data_wrapper(): void
    {
        $response = $this->getJson('/api/playlists/featured');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Single Playlist ─────────────────────────────────────────

    public function test_show_playlist_returns_resource(): void
    {
        $response = $this->getJson("/api/playlists/{$this->playlist->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'links',
                ],
            ]);
    }

    public function test_playlist_not_found_returns_json_404(): void
    {
        $response = $this->getJson('/api/playlists/nonexistent-playlist-xyz');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    // ─── Playlist Tracks ─────────────────────────────────────────

    public function test_playlist_tracks_returns_data_wrapper(): void
    {
        $response = $this->getJson("/api/playlists/{$this->playlist->id}/tracks");

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ─── Response Format ─────────────────────────────────────────

    public function test_playlist_responses_contain_no_success_key(): void
    {
        $endpoints = [
            '/api/playlists',
            '/api/playlists/featured',
            "/api/playlists/{$this->playlist->slug}",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertOk();
            $this->assertArrayNotHasKey('success', $response->json(), "Endpoint {$endpoint} still has 'success' key");
        }
    }

    // ─── Authenticated Playlist CRUD ─────────────────────────────

    public function test_create_playlist_returns_resource(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/playlists', [
            'name' => 'My Test Playlist',
            'description' => 'A test playlist',
            'visibility' => 'public',
        ]);

        $response->assertHeader('Content-Type', 'application/json');
        $this->assertContains($response->status(), [200, 201], 'Create playlist should return 200 or 201');
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'slug'],
        ]);
    }
}
