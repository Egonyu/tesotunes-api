<?php

namespace Tests\Feature\Api\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use Tests\TestCase;

class LoyaltyAdminApiTest extends TestCase
{
    private User $admin;
    private LoyaltyCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        // Assume admin role is checked at middleware level; these tests verify controller logic
        $this->card = LoyaltyCard::factory()->create();
    }

    public function test_admin_can_list_all_cards(): void
    {
        LoyaltyCard::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/loyalty/cards');

        $response->assertOk();
    }

    public function test_admin_can_view_card_detail(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/admin/loyalty/cards/{$this->card->id}");

        $response->assertOk();
    }

    public function test_admin_can_approve_card(): void
    {
        $draft = LoyaltyCard::factory()->draft()->create();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/loyalty/cards/{$draft->id}/approve");

        $response->assertOk();
        $this->assertDatabaseHas('loyalty_cards', [
            'id'     => $draft->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_suspend_card(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/loyalty/cards/{$this->card->id}/suspend", [
            'reason' => 'Violates terms of service',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('loyalty_cards', [
            'id'     => $this->card->id,
            'status' => 'suspended',
        ]);
    }

    public function test_admin_can_view_analytics(): void
    {
        LoyaltyCardMember::factory()->count(3)->create(['loyalty_card_id' => $this->card->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/loyalty/analytics');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'total_cards',
                'active_cards',
                'total_members',
                'active_members',
                'total_redemptions',
                'members_by_tier',
                'top_cards',
            ],
        ]);
    }
}
