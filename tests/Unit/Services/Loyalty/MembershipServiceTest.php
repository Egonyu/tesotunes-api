<?php

namespace Tests\Unit\Services\Loyalty;

use App\Models\Artist;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use App\Services\Loyalty\MembershipService;
use Tests\TestCase;

class MembershipServiceTest extends TestCase
{
    private MembershipService $service;
    private User $user;
    private LoyaltyCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MembershipService::class);
        $this->user    = User::factory()->create();
        $this->card    = LoyaltyCard::factory()->create();
    }

    public function test_subscribe_creates_active_membership(): void
    {
        $member = $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'bronze',
            subscriptionType: 'monthly',
            paymentMethod: 'mobile_money',
        );

        $this->assertInstanceOf(LoyaltyCardMember::class, $member);
        $this->assertEquals('active', $member->status);
        $this->assertEquals('bronze', $member->tier);
        $this->assertEquals($this->user->id, $member->user_id);
        $this->assertEquals($this->card->id, $member->loyalty_card_id);
    }

    public function test_subscribe_throws_when_card_not_active(): void
    {
        $draft = LoyaltyCard::factory()->draft()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not currently accepting');

        $this->service->subscribe(
            user: $this->user,
            card: $draft,
            tier: 'bronze',
            subscriptionType: 'monthly',
        );
    }

    public function test_subscribe_throws_for_invalid_tier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tier');

        $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'diamond',
            subscriptionType: 'monthly',
        );
    }

    public function test_subscribe_throws_for_duplicate_active_membership(): void
    {
        $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'bronze',
            subscriptionType: 'monthly',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already');

        $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'silver',
            subscriptionType: 'monthly',
        );
    }

    public function test_cancel_sets_status_to_cancelled(): void
    {
        $member = $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'bronze',
            subscriptionType: 'monthly',
        );

        $this->service->cancel($member);

        $member->refresh();
        $this->assertEquals('cancelled', $member->status);
    }

    public function test_renew_extends_expiry(): void
    {
        $member = LoyaltyCardMember::factory()->create([
            'loyalty_card_id'   => $this->card->id,
            'user_id'           => $this->user->id,
            'subscription_type' => 'monthly',
            'expires_at'        => now()->addDays(2),
        ]);

        $oldExpiry = $member->expires_at->copy();
        $this->service->renew($member);

        $member->refresh();
        $this->assertGreaterThan($oldExpiry, $member->expires_at);
    }

    public function test_change_tier_updates_tier(): void
    {
        $member = $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'bronze',
            subscriptionType: 'monthly',
        );

        $this->service->changeTier($member, 'silver');

        $member->refresh();
        $this->assertEquals('silver', $member->tier);
    }

    public function test_change_tier_throws_for_invalid_tier(): void
    {
        $member = $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'bronze',
            subscriptionType: 'monthly',
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->service->changeTier($member, 'diamond');
    }

    public function test_subscribe_increments_total_members(): void
    {
        $initialCount = $this->card->total_members;

        $this->service->subscribe(
            user: $this->user,
            card: $this->card,
            tier: 'bronze',
            subscriptionType: 'monthly',
        );

        $this->card->refresh();
        $this->assertEquals($initialCount + 1, $this->card->total_members);
    }
}
