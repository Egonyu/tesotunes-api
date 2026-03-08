<?php

namespace Tests\Feature\Api\Social;

use App\Models\Artist;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use DatabaseTransactions;

    // ━━━ Follow Artist ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_authenticated_user_can_follow_artist(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Artist followed successfully.',
                'data' => ['is_following' => true],
            ]);

        $this->assertDatabaseHas('user_follows', [
            'follower_id' => $user->id,
            'followable_id' => $artist->id,
            'followable_type' => Artist::class,
        ]);
    }

    public function test_follow_requires_authentication(): void
    {
        $artist = Artist::factory()->create(['status' => 'active']);

        $response = $this->postJson("/api/artists/{$artist->id}/follow");

        $response->assertUnauthorized();
    }

    public function test_user_cannot_follow_own_artist_profile(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You cannot follow yourself.',
            ]);
    }

    public function test_duplicate_follow_is_idempotent(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['status' => 'active']);

        // Follow once
        $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow")->assertOk();

        // Follow again — should not fail
        $response = $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Already following this artist.',
            ]);

        // Should only have one follow record
        $this->assertEquals(1, UserFollow::where('follower_id', $user->id)
            ->where('followable_id', $artist->id)
            ->where('followable_type', Artist::class)
            ->count());
    }

    public function test_follow_increments_followers_count(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['followers_count' => 100, 'status' => 'active']);

        $this->actingAs($user)->postJson("/api/artists/{$artist->id}/follow")->assertOk();

        $this->assertEquals(101, $artist->fresh()->followers_count);
    }

    // ━━━ Unfollow Artist ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_authenticated_user_can_unfollow_artist(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['status' => 'active']);

        // Follow first
        UserFollow::create([
            'follower_id' => $user->id,
            'following_id' => $artist->user_id,
            'followable_id' => $artist->id,
            'followable_type' => Artist::class,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/artists/{$artist->id}/follow");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['is_following' => false],
            ]);

        $this->assertDatabaseMissing('user_follows', [
            'follower_id' => $user->id,
            'followable_id' => $artist->id,
            'followable_type' => Artist::class,
        ]);
    }

    public function test_unfollow_requires_authentication(): void
    {
        $artist = Artist::factory()->create(['status' => 'active']);

        $response = $this->deleteJson("/api/artists/{$artist->id}/follow");

        $response->assertUnauthorized();
    }

    public function test_unfollow_decrements_followers_count(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['followers_count' => 50, 'status' => 'active']);

        UserFollow::create([
            'follower_id' => $user->id,
            'following_id' => $artist->user_id,
            'followable_id' => $artist->id,
            'followable_type' => Artist::class,
        ]);

        $this->actingAs($user)->deleteJson("/api/artists/{$artist->id}/follow")->assertOk();

        $this->assertEquals(49, $artist->fresh()->followers_count);
    }

    // ━━━ Follow Status ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_user_can_check_follow_status(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $artist = Artist::factory()->create(['status' => 'active']);

        // Not following yet
        $response = $this->actingAs($user)->getJson("/api/artists/{$artist->id}/follow/status");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['is_following' => false],
            ]);

        // Follow
        UserFollow::create([
            'follower_id' => $user->id,
            'following_id' => $artist->user_id,
            'followable_id' => $artist->id,
            'followable_type' => Artist::class,
        ]);

        // Now following
        $response = $this->actingAs($user)->getJson("/api/artists/{$artist->id}/follow/status");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['is_following' => true],
            ]);
    }

    public function test_follow_status_requires_authentication(): void
    {
        $artist = Artist::factory()->create(['status' => 'active']);

        $response = $this->getJson("/api/artists/{$artist->id}/follow/status");

        $response->assertUnauthorized();
    }
}
