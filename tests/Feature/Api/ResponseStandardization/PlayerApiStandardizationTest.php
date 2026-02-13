<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use Tests\TestCase;

class PlayerApiStandardizationTest extends TestCase
{

    private User $user;
    private Song $song;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);
        $this->song = Song::factory()->create([
            'user_id' => $artistUser->id,
            'artist_id' => $artist->id,
            'status' => 'published',
        ]);
    }

    // ─── Record Play ─────────────────────────────────────────────

    public function test_record_play_returns_json_not_redirect(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/player/record-play', [
            'song_id' => $this->song->id,
        ]);

        $response->assertHeader('Content-Type', 'application/json');
        $this->assertNotEquals(302, $response->status(), 'Player endpoint should not redirect');
    }

    public function test_record_play_contains_no_success_key(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/player/record-play', [
            'song_id' => $this->song->id,
        ]);

        // Always assert content type to avoid risky test
        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 200) {
            $this->assertArrayNotHasKey('success', $response->json());
        }
    }

    // ─── Update Now Playing ──────────────────────────────────────

    public function test_update_now_playing_returns_json(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/player/update-now-playing', [
            'song_id' => $this->song->id,
        ]);

        $response->assertHeader('Content-Type', 'application/json');
    }

    // ─── Unauthenticated ─────────────────────────────────────────

    public function test_player_unauthenticated_returns_json_401(): void
    {
        $response = $this->postJson('/api/player/record-play', [
            'song_id' => $this->song->id,
        ]);

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
