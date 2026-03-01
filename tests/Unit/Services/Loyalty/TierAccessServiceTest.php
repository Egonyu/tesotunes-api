<?php

namespace Tests\Unit\Services\Loyalty;

use App\Models\Event;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use App\Services\Loyalty\TierAccessService;
use Tests\TestCase;

class TierAccessServiceTest extends TestCase
{
    private TierAccessService $service;

    private User $user;

    private LoyaltyCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TierAccessService::class);
        $this->user = User::factory()->create();
        $this->card = LoyaltyCard::factory()->create();
    }

    public function test_can_access_event_without_tier_requirement(): void
    {
        $event = Event::factory()->create([
            'required_loyalty_tier' => null,
        ]);

        $result = $this->service->canAccessEvent($this->user, $event);

        $this->assertTrue($result['can_access']);
    }

    public function test_cannot_access_tier_gated_event_without_membership(): void
    {
        $event = Event::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_loyalty_tier' => 'gold',
        ]);

        $result = $this->service->canAccessEvent($this->user, $event);

        $this->assertFalse($result['can_access']);
    }

    public function test_can_access_tier_gated_event_with_qualifying_membership(): void
    {
        $event = Event::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_loyalty_tier' => 'bronze',
        ]);

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id' => $this->user->id,
            'tier' => 'silver',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $result = $this->service->canAccessEvent($this->user, $event);

        $this->assertTrue($result['can_access']);
    }

    public function test_cannot_access_with_lower_tier(): void
    {
        $event = Event::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_loyalty_tier' => 'gold',
        ]);

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id' => $this->user->id,
            'tier' => 'bronze',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $result = $this->service->canAccessEvent($this->user, $event);

        $this->assertFalse($result['can_access']);
    }

    public function test_get_user_tier_for_card(): void
    {
        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id' => $this->user->id,
            'tier' => 'gold',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $tier = $this->service->getUserTierForCard($this->user, $this->card->id);

        $this->assertEquals('gold', $tier);
    }

    public function test_get_user_tier_returns_null_without_membership(): void
    {
        $tier = $this->service->getUserTierForCard($this->user, $this->card->id);

        $this->assertNull($tier);
    }

    public function test_expired_membership_does_not_grant_access(): void
    {
        $event = Event::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_loyalty_tier' => 'bronze',
        ]);

        LoyaltyCardMember::factory()->expired()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id' => $this->user->id,
            'tier' => 'gold',
        ]);

        $result = $this->service->canAccessEvent($this->user, $event);

        $this->assertFalse($result['can_access']);
    }
}
