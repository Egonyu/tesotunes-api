<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);

        Broadcast::forgetDrivers();
        app()->forgetInstance(\Illuminate\Broadcasting\BroadcastManager::class);
        app()->forgetInstance(\Illuminate\Contracts\Broadcasting\Broadcaster::class);

        require base_path('routes/channels.php');
    }

    public function test_broadcasting_auth_requires_authentication(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-user.1',
            'socket_id' => '1234.5678',
        ])->assertUnauthorized();
    }

    public function test_user_can_authorize_their_own_private_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->post('/api/broadcasting/auth', [
            'channel_name' => 'private-user.'.$user->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/broadcasting/auth', [
            'channel_name' => 'private-user.'.($user->id + 1),
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_active_fan_club_member_can_authorize_fan_club_channel(): void
    {
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);
        $card = LoyaltyCard::factory()->create(['artist_id' => $artist->id]);
        $member = User::factory()->create();

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $card->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($member);

        $this->post('/api/broadcasting/auth', [
            'channel_name' => 'private-fan-club.'.$card->id,
            'socket_id' => '1234.5678',
        ])->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_non_member_cannot_authorize_fan_club_channel(): void
    {
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);
        $card = LoyaltyCard::factory()->create(['artist_id' => $artist->id]);
        $viewer = User::factory()->create();

        Sanctum::actingAs($viewer);

        $this->post('/api/broadcasting/auth', [
            'channel_name' => 'private-fan-club.'.$card->id,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
