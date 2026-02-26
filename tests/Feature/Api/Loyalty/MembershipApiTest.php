<?php

namespace Tests\Feature\Api\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\LoyaltyPoints;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Tests\TestCase;

class MembershipApiTest extends TestCase
{
    private User $user;
    private LoyaltyCard $card;
    private LoyaltyCardMember $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->card = LoyaltyCard::factory()->create();

        $this->membership = LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id'         => $this->user->id,
            'tier'            => 'bronze',
        ]);
    }

    public function test_fan_can_list_memberships(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/my/memberships');

        $response->assertOk();
    }

    public function test_fan_can_view_membership_detail(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/my/memberships/{$this->membership->id}");

        $response->assertOk();
    }

    public function test_fan_cannot_view_other_users_membership(): void
    {
        $otherUser   = User::factory()->create();
        $otherMember = LoyaltyCardMember::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->getJson("/api/my/memberships/{$otherMember->id}");

        $response->assertNotFound();
    }

    public function test_fan_can_toggle_auto_renew(): void
    {
        $response = $this->actingAs($this->user)->putJson("/api/my/memberships/{$this->membership->id}", [
            'auto_renew' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('loyalty_card_members', [
            'id'         => $this->membership->id,
            'auto_renew' => false,
        ]);
    }

    public function test_fan_can_cancel_membership(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/my/memberships/{$this->membership->id}/cancel");

        $response->assertOk();
        $this->assertDatabaseHas('loyalty_card_members', [
            'id'     => $this->membership->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_fan_can_renew_membership(): void
    {
        $this->membership->update(['expires_at' => now()->addDays(2)]);

        $response = $this->actingAs($this->user)->postJson("/api/my/memberships/{$this->membership->id}/renew");

        $response->assertOk();
    }

    // ──────── Loyalty Points Endpoints ────────

    public function test_fan_can_view_points_balance(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance'         => 1000,
                'lifetime_earned' => 1500,
                'lifetime_spent'  => 500,
            ]
        );

        $response = $this->actingAs($this->user)->getJson('/api/my/loyalty-points');

        $response->assertOk();
        $response->assertJsonFragment(['balance' => 1000]);
    }

    public function test_fan_can_view_transaction_history(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance'         => 100,
                'lifetime_earned' => 100,
                'lifetime_spent'  => 0,
            ]
        );

        LoyaltyTransaction::create([
            'user_id'     => $this->user->id,
            'type'        => 'earned',
            'points'      => 100,
            'source'      => 'event_checkin',
            'description' => 'Test check-in',
            'created_at'  => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/my/loyalty-points/transactions');

        $response->assertOk();
    }

    public function test_fan_can_convert_points_to_credits(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance'         => 500,
                'lifetime_earned' => 500,
                'lifetime_spent'  => 0,
            ]
        );

        $response = $this->actingAs($this->user)->postJson('/api/my/loyalty-points/convert', [
            'points' => 100,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'points_spent', 'credits_earned']);
    }

    public function test_fan_cannot_convert_more_points_than_balance(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance'         => 50,
                'lifetime_earned' => 50,
                'lifetime_spent'  => 0,
            ]
        );

        $response = $this->actingAs($this->user)->postJson('/api/my/loyalty-points/convert', [
            'points' => 100,
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_cannot_access_points(): void
    {
        $response = $this->getJson('/api/my/loyalty-points');

        $response->assertUnauthorized();
    }
}
