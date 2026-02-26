<?php

namespace Tests\Unit\Services\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyReward;
use App\Models\Loyalty\LoyaltyRewardRedemption;
use App\Models\LoyaltyPoints;
use App\Models\User;
use App\Services\Loyalty\RewardService;
use Tests\TestCase;

class RewardServiceTest extends TestCase
{
    private RewardService $service;
    private User $user;
    private LoyaltyCard $card;
    private LoyaltyCardMember $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RewardService::class);
        $this->user    = User::factory()->create();
        $this->card    = LoyaltyCard::factory()->create();

        $this->member = LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'user_id'         => $this->user->id,
            'tier'            => 'silver',
        ]);

        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance'         => 1000,
                'lifetime_earned' => 1000,
                'lifetime_spent'  => 0,
            ]
        );
    }

    public function test_get_available_rewards_returns_tier_eligible(): void
    {
        $bronzeReward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
        ]);

        $goldReward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'gold',
        ]);

        $rewards = $this->service->getAvailableRewards($this->member);

        $rewardIds = $rewards->pluck('id')->toArray();
        $this->assertContains($bronzeReward->id, $rewardIds);
        $this->assertNotContains($goldReward->id, $rewardIds);
    }

    public function test_redeem_reward_creates_redemption(): void
    {
        $reward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
            'points_amount'   => 100,
            'type'            => 'content',
            'content_url'     => 'https://example.com/track.mp3',
        ]);

        $redemption = $this->service->redeemReward($this->user, $reward);

        $this->assertInstanceOf(LoyaltyRewardRedemption::class, $redemption);
        $this->assertEquals($this->user->id, $redemption->user_id);
        $this->assertEquals($reward->id, $redemption->loyalty_reward_id);
    }

    public function test_redeem_reward_spends_points(): void
    {
        $reward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
            'points_amount'   => 200,
            'type'            => 'content',
            'content_url'     => 'https://example.com/track.mp3',
        ]);

        $this->service->redeemReward($this->user, $reward);

        $points = LoyaltyPoints::where('user_id', $this->user->id)->first();
        $this->assertEquals(800, $points->balance);
    }

    public function test_redeem_reward_throws_when_tier_insufficient(): void
    {
        $reward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'platinum',
            'points_amount'   => 50,
            'type'            => 'content',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->redeemReward($this->user, $reward);
    }

    public function test_redeem_reward_throws_when_inactive(): void
    {
        $reward = LoyaltyReward::factory()->inactive()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
            'points_amount'   => 50,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->redeemReward($this->user, $reward);
    }

    public function test_cancel_redemption_decrements_counter(): void
    {
        $reward = LoyaltyReward::factory()->create([
            'loyalty_card_id'     => $this->card->id,
            'required_tier'       => 'bronze',
            'points_amount'       => 100,
            'type'                => 'merchandise',
            'current_redemptions' => 1,
        ]);

        $redemption = LoyaltyRewardRedemption::create([
            'loyalty_reward_id'      => $reward->id,
            'loyalty_card_member_id' => $this->member->id,
            'user_id'                => $this->user->id,
            'status'                 => 'pending',
        ]);

        $this->service->cancelRedemption($redemption);

        $redemption->refresh();
        $this->assertEquals('cancelled', $redemption->status);

        $reward->refresh();
        $this->assertEquals(0, $reward->current_redemptions);
    }

    public function test_fulfil_redemption_sets_fulfilled_status(): void
    {
        $reward = LoyaltyReward::factory()->create([
            'loyalty_card_id' => $this->card->id,
            'required_tier'   => 'bronze',
            'points_amount'   => 100,
            'type'            => 'merchandise',
        ]);

        $redemption = LoyaltyRewardRedemption::create([
            'loyalty_reward_id'      => $reward->id,
            'loyalty_card_member_id' => $this->member->id,
            'user_id'                => $this->user->id,
            'status'                 => 'pending',
        ]);

        $this->service->fulfilRedemption($redemption, 'Shipped via DHL');

        $redemption->refresh();
        $this->assertEquals('fulfilled', $redemption->status);
        $this->assertEquals('Shipped via DHL', $redemption->fulfilment_notes);
    }
}
