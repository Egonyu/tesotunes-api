<?php

namespace Tests\Feature\Api\Social;

use App\Models\Like;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LikeStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_check_like_status(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->getJson("/api/like/song/{$song->id}/status");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_liked' => false,
                ],
            ]);
    }

    public function test_like_status_returns_true_when_liked(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        Like::create([
            'user_id' => $user->id,
            'likeable_type' => Song::class,
            'likeable_id' => $song->id,
            'type' => 'like',
        ]);

        $response = $this->actingAs($user)->getJson("/api/like/song/{$song->id}/status");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_liked' => true,
                ],
            ]);
    }

    public function test_like_status_requires_authentication(): void
    {
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->getJson("/api/like/song/{$song->id}/status");

        $response->assertUnauthorized();
    }

    public function test_like_status_rejects_invalid_type(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->getJson("/api/like/invalid_type/999/status");

        $response->assertStatus(422);
    }

    public function test_like_status_returns_404_for_missing_entity(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->getJson("/api/like/song/999999/status");

        $response->assertStatus(404);
    }

    public function test_like_status_returns_likes_count(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft', 'like_count' => 42]);

        $response = $this->actingAs($user)->getJson("/api/like/song/{$song->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.likes_count', 42);
    }
}
