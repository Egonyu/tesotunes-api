<?php

namespace Tests\Unit\Models\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use Tests\TestCase;

class LoyaltyCardMemberModelTest extends TestCase
{
    public function test_is_active_when_status_active_and_not_expired(): void
    {
        $member = LoyaltyCardMember::factory()->create([
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $this->assertTrue($member->isActive());
    }

    public function test_is_not_active_when_expired(): void
    {
        $member = LoyaltyCardMember::factory()->expired()->create();

        $this->assertFalse($member->isActive());
        $this->assertTrue($member->isExpired());
    }

    public function test_is_not_active_when_cancelled(): void
    {
        $member = LoyaltyCardMember::factory()->cancelled()->create();

        $this->assertFalse($member->isActive());
    }

    public function test_tier_level_returns_correct_numeric_value(): void
    {
        $member = LoyaltyCardMember::factory()->create(['tier' => 'gold']);

        $this->assertEquals(3, $member->tierLevel());
    }

    public function test_meets_or_exceeds_tier_returns_true_for_equal(): void
    {
        $member = LoyaltyCardMember::factory()->create(['tier' => 'silver']);

        $this->assertTrue($member->meetsOrExceedsTier('silver'));
    }

    public function test_meets_or_exceeds_tier_returns_true_for_higher(): void
    {
        $member = LoyaltyCardMember::factory()->gold()->create();

        $this->assertTrue($member->meetsOrExceedsTier('bronze'));
        $this->assertTrue($member->meetsOrExceedsTier('silver'));
    }

    public function test_meets_or_exceeds_tier_returns_false_for_lower(): void
    {
        $member = LoyaltyCardMember::factory()->create(['tier' => 'bronze']);

        $this->assertFalse($member->meetsOrExceedsTier('gold'));
    }

    public function test_belongs_to_loyalty_card(): void
    {
        $card = LoyaltyCard::factory()->create();
        $member = LoyaltyCardMember::factory()->create(['loyalty_card_id' => $card->id]);

        $this->assertEquals($card->id, $member->loyaltyCard->id);
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $member = LoyaltyCardMember::factory()->create(['user_id' => $user->id]);

        $this->assertEquals($user->id, $member->user->id);
    }

    public function test_active_scope_excludes_expired_and_cancelled(): void
    {
        $card = LoyaltyCard::factory()->create();

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $card->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);
        LoyaltyCardMember::factory()->expired()->create(['loyalty_card_id' => $card->id]);
        LoyaltyCardMember::factory()->cancelled()->create(['loyalty_card_id' => $card->id]);

        $active = LoyaltyCardMember::active()->forCard($card->id)->get();

        $this->assertCount(1, $active);
    }

    public function test_expiring_scope_finds_soon_expiring(): void
    {
        $card = LoyaltyCard::factory()->create();

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $card->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2),
        ]);

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $card->id,
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        $expiring = LoyaltyCardMember::expiring(7)->get();

        $this->assertCount(1, $expiring);
    }

    public function test_points_multiplier_returns_default_when_no_tier_config(): void
    {
        $member = LoyaltyCardMember::factory()->create(['tier' => 'bronze']);

        $multiplier = $member->pointsMultiplier();

        $this->assertGreaterThanOrEqual(1.0, $multiplier);
    }
}
