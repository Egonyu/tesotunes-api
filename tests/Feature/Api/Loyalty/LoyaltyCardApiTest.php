<?php

namespace Tests\Feature\Api\Loyalty;

use App\Models\Artist;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyReward;
use App\Models\LoyaltyPoints;
use App\Models\User;
use Tests\TestCase;

class LoyaltyCardApiTest extends TestCase
{
    private User $user;
    private User $artistUser;
    private Artist $artist;
    private LoyaltyCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user       = User::factory()->create();
        $this->artistUser = User::factory()->create();
        $this->artist     = Artist::factory()->create(['user_id' => $this->artistUser->id]);

        $this->card = LoyaltyCard::factory()->create([
            'artist_id' => $this->artist->id,
        ]);
    }

    // ──────── Public Endpoints ────────

    public function test_public_can_list_loyalty_cards(): void
    {
        LoyaltyCard::factory()->count(3)->create();

        $response = $this->getJson('/api/loyalty-cards');

        $response->assertOk();
    }

    public function test_public_can_view_loyalty_card_by_slug(): void
    {
        $response = $this->getJson("/api/loyalty-cards/{$this->card->slug}");

        $response->assertOk();
    }

    public function test_public_cannot_view_draft_card(): void
    {
        $draft = LoyaltyCard::factory()->draft()->create(['artist_id' => $this->artist->id]);

        $response = $this->getJson("/api/loyalty-cards/{$draft->slug}");

        // Should 404 since public endpoint filters by published status
        $response->assertNotFound();
    }

    // ──────── Authenticated Fan Endpoints ────────

    public function test_fan_can_join_loyalty_card(): void
    {
        $response = $this->actingAs($this->user)->postJson("/api/loyalty-cards/{$this->card->slug}/join", [
            'tier'              => 'bronze',
            'subscription_type' => 'monthly',
            'payment_method'    => 'mobile_money',
            'mobile_number'     => '0771234567',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['tier' => 'bronze']);

        $this->assertDatabaseHas('loyalty_card_members', [
            'user_id'         => $this->user->id,
            'loyalty_card_id' => $this->card->id,
            'tier'            => 'bronze',
            'status'          => 'active',
        ]);
    }

    public function test_fan_cannot_join_same_card_twice(): void
    {
        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id'         => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/loyalty-cards/{$this->card->slug}/join", [
            'tier'              => 'silver',
            'subscription_type' => 'monthly',
            'payment_method'    => 'mobile_money',
        ]);

        $response->assertStatus(422);
    }

    public function test_fan_can_view_available_rewards(): void
    {
        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id'         => $this->user->id,
            'tier'            => 'silver',
        ]);

        LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
        ]);

        LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'gold',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/loyalty-cards/{$this->card->slug}/rewards");

        $response->assertOk();
    }

    public function test_fan_can_redeem_reward(): void
    {
        $member = LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id'         => $this->user->id,
            'tier'            => 'silver',
        ]);

        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance'         => 500,
                'lifetime_earned' => 500,
                'lifetime_spent'  => 0,
            ]
        );

        $reward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
            'points_amount'   => 100,
            'type'            => 'content',
            'content_url'     => 'https://example.com/exclusive-track.mp3',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/loyalty-cards/{$this->card->slug}/rewards/{$reward->id}/redeem"
        );

        $response->assertOk();
    }

    public function test_unauthenticated_cannot_join(): void
    {
        $response = $this->postJson("/api/loyalty-cards/{$this->card->slug}/join", [
            'tier'              => 'bronze',
            'subscription_type' => 'monthly',
            'payment_method'    => 'mobile_money',
        ]);

        $response->assertUnauthorized();
    }

    // ──────── Artist Endpoints ────────

    public function test_artist_can_list_own_cards(): void
    {
        $response = $this->actingAs($this->artistUser)->getJson('/api/artist/loyalty-cards');

        $response->assertOk();
    }

    public function test_artist_can_create_loyalty_card(): void
    {
        $response = $this->actingAs($this->artistUser)->postJson('/api/artist/loyalty-cards', [
            'name'        => 'My Fan Club',
            'description' => 'The best fan club ever',
            'tiers'       => [
                [
                    'name'          => 'bronze',
                    'price_monthly' => 5000,
                    'price_yearly'  => 50000,
                    'benefits'      => ['Early access'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'My Fan Club']);
    }

    public function test_artist_can_update_loyalty_card(): void
    {
        $response = $this->actingAs($this->artistUser)->putJson("/api/artist/loyalty-cards/{$this->card->slug}", [
            'description' => 'Updated description',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('loyalty_cards', [
            'id'          => $this->card->id,
            'description' => 'Updated description',
        ]);
    }

    public function test_artist_can_publish_card(): void
    {
        config(['loyalty.requires_admin_approval' => false]);

        $draft = LoyaltyCard::factory()->draft()->create(['artist_id' => $this->artist->id]);

        $response = $this->actingAs($this->artistUser)->postJson("/api/artist/loyalty-cards/{$draft->slug}/publish");

        $response->assertOk();
        $this->assertDatabaseHas('loyalty_cards', [
            'id'     => $draft->id,
            'status' => 'active',
        ]);
    }

    public function test_artist_can_view_members(): void
    {
        LoyaltyCardMember::factory()->count(3)->create(['loyalty_card_id' => $this->card->id]);

        $response = $this->actingAs($this->artistUser)->getJson("/api/artist/loyalty-cards/{$this->card->slug}/members");

        $response->assertOk();
    }

    public function test_artist_can_view_analytics(): void
    {
        $response = $this->actingAs($this->artistUser)->getJson("/api/artist/loyalty-cards/{$this->card->slug}/analytics");

        $response->assertOk();
    }

    public function test_artist_can_delete_draft_card(): void
    {
        $draft = LoyaltyCard::factory()->draft()->create(['artist_id' => $this->artist->id]);

        $response = $this->actingAs($this->artistUser)->deleteJson("/api/artist/loyalty-cards/{$draft->slug}");

        $response->assertOk();
    }

    // ──────── Artist Reward Endpoints ────────

    public function test_artist_can_create_reward(): void
    {
        $response = $this->actingAs($this->artistUser)->postJson(
            "/api/artist/loyalty-cards/{$this->card->slug}/rewards",
            [
                'name'          => 'Exclusive Track',
                'description'   => 'An unreleased track',
                'type'          => 'content',
                'required_tier' => 'bronze',
                'points_amount' => 100,
                'content_type'  => 'audio',
                'content_url'   => 'https://cdn.example.com/track.mp3',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Exclusive Track']);
    }

    public function test_artist_can_list_rewards(): void
    {
        LoyaltyReward::factory()->count(3)->create(['loyalty_card_id' => $this->card->id]);

        $response = $this->actingAs($this->artistUser)->getJson(
            "/api/artist/loyalty-cards/{$this->card->slug}/rewards"
        );

        $response->assertOk();
    }
}
