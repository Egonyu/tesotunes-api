<?php

namespace Tests\Feature\Api\Social;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ShareTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_share_a_song(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson('/api/shares', [
            'shareable_type' => 'Song',
            'shareable_id' => $song->id,
            'platform' => 'whatsapp',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Content shared successfully',
            ]);

        $this->assertDatabaseHas('shares', [
            'user_id' => $user->id,
            'shareable_type' => 'App\\Models\\Song',
            'shareable_id' => $song->id,
            'platform' => 'whatsapp',
        ]);
    }

    public function test_share_requires_authentication(): void
    {
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->postJson('/api/shares', [
            'shareable_type' => 'Song',
            'shareable_id' => $song->id,
            'platform' => 'facebook',
        ]);

        $response->assertUnauthorized();
    }

    public function test_share_validates_required_fields(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/shares', []);

        $response->assertStatus(422);
    }

    public function test_share_validates_platform(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson('/api/shares', [
            'shareable_type' => 'Song',
            'shareable_id' => $song->id,
            'platform' => 'invalid_platform',
        ]);

        $response->assertStatus(422);
    }

    public function test_share_returns_share_payload(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson('/api/shares', [
            'shareable_type' => 'Song',
            'shareable_id' => $song->id,
            'platform' => 'copy',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'share',
                    'share_payload',
                ],
            ]);
    }

    public function test_share_with_message(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $song = Song::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($user)->postJson('/api/shares', [
            'shareable_type' => 'Song',
            'shareable_id' => $song->id,
            'platform' => 'twitter',
            'message' => 'Check out this song!',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('shares', [
            'user_id' => $user->id,
            'message' => 'Check out this song!',
        ]);
    }

    public function test_share_invalid_type_returns_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/shares', [
            'shareable_type' => 'InvalidModel',
            'shareable_id' => 999,
            'platform' => 'copy',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid shareable type',
            ]);
    }

    public function test_user_can_list_own_shares(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/shares');

        $response->assertOk()
            ->assertJson(['success' => true]);
    }
}
