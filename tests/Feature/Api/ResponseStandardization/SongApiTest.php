<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;

class SongApiTest extends ResponseStandardizationTestCase
{
    private User $user;

    private Artist $artist;

    private Song $song;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->artist = Artist::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
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

    public function test_list_songs_supports_limit_alias_and_sort_parameter(): void
    {
        Song::query()->update(['play_count' => 1]);

        Song::factory()->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'title' => 'Older song',
            'play_count' => 5,
            'created_at' => now()->subDays(5),
        ]);

        $newerSong = Song::factory()->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'title' => 'Newer song',
            'play_count' => 50,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->getJson("/api/songs?artist={$this->artist->id}&limit=1&sort=-play_count");

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerSong->id);
    }

    public function test_list_songs_supports_period_filter(): void
    {
        Song::query()->update(['created_at' => now()->subDays(90)]);

        Song::factory()->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'title' => 'Old release',
            'created_at' => now()->subDays(45),
        ]);

        $recentSong = Song::factory()->create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'title' => 'Recent release',
            'created_at' => now()->subDays(3),
        ]);

        $response = $this->getJson("/api/songs?artist={$this->artist->id}&period=week&sort=-created_at");

        $response->assertOk();

        $songIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($recentSong->id, $songIds);
        $this->assertCount(1, $songIds);
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
                    'duration_seconds',
                    'duration_formatted',
                    'audio_url',
                    'stream_url',
                    'preview_url',
                    'artwork_url',
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
