<?php

namespace Tests\Feature\Api\Social;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LikeTest extends TestCase
{
    use DatabaseTransactions;

    // ━━━ Toggle Like ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_authenticated_user_can_like_a_song(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson("/api/like/song/{$song->id}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'liked' => true,
                    'type' => 'song',
                    'id' => $song->id,
                ],
                'message' => 'Liked successfully',
            ]);
    }

    public function test_like_toggle_unlikes_when_already_liked(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        // Like first
        $this->actingAs($user)->postJson("/api/like/song/{$song->id}")
            ->assertOk()
            ->assertJson(['data' => ['liked' => true]]);

        // Toggle = unlike
        $response = $this->actingAs($user)->postJson("/api/like/song/{$song->id}");

        $response->assertOk()
            ->assertJson([
                'data' => ['liked' => false],
                'message' => 'Unliked successfully',
            ]);
    }

    public function test_like_requires_authentication(): void
    {
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->postJson("/api/like/song/{$song->id}");

        $response->assertUnauthorized();
    }

    // ━━━ Entity Types ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_like_rejects_unsupported_entity_type(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/like/invalidtype/1');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Unsupported entity type: invalidtype']);
    }

    public function test_like_returns_404_for_nonexistent_entity(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/like/song/999999');

        $response->assertNotFound();
    }

    // ━━━ Like Count ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_like_returns_like_count(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson("/api/like/song/{$song->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['liked', 'type', 'id', 'like_count'],
            ]);
    }

    // ━━━ Multiple Entity Types ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_like_supports_plural_type_names(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        // "songs" (plural) should also work
        $response = $this->actingAs($user)->postJson("/api/like/songs/{$song->id}");

        $response->assertOk()
            ->assertJson(['data' => ['liked' => true]]);
    }
}
