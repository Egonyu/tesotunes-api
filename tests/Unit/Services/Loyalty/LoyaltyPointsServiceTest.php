<?php

namespace Tests\Unit\Services\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\LoyaltyPoints;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Services\Loyalty\LoyaltyPointsService;
use Tests\TestCase;

class LoyaltyPointsServiceTest extends TestCase
{
    private LoyaltyPointsService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LoyaltyPointsService::class);
        $this->user = User::factory()->create();
    }

    public function test_award_points_creates_transaction_and_updates_balance(): void
    {
        $transaction = $this->service->awardPoints(
            user: $this->user,
            basePoints: 100,
            source: 'test',
            description: 'Test award',
        );

        $this->assertInstanceOf(LoyaltyTransaction::class, $transaction);
        $this->assertEquals('earned', $transaction->type);
        $this->assertGreaterThanOrEqual(100, $transaction->points);

        $points = LoyaltyPoints::where('user_id', $this->user->id)->first();
        $this->assertNotNull($points);
        $this->assertGreaterThanOrEqual(100, $points->balance);
        $this->assertGreaterThanOrEqual(100, $points->lifetime_earned);
    }

    public function test_award_points_applies_multiplier_from_active_membership(): void
    {
        $card = LoyaltyCard::factory()->create([
            'tiers' => [
                [
                    'name' => 'gold',
                    'price_monthly' => 25000,
                    'price_yearly' => 250000,
                    'benefits' => [
                        'loyalty_points_multiplier' => 2.0,
                    ],
                ],
            ],
        ]);

        LoyaltyCardMember::factory()->create([
            'loyalty_card_id' => $card->id,
            'user_id' => $this->user->id,
            'tier' => 'gold',
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        $transaction = $this->service->awardPoints(
            user: $this->user,
            basePoints: 100,
            source: 'test',
        );

        $this->assertEquals(200, $transaction->points);
    }

    public function test_spend_points_deducts_balance(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance' => 500,
                'lifetime_earned' => 500,
                'lifetime_spent' => 0,
            ]
        );

        $transaction = $this->service->spendPoints(
            user: $this->user,
            points: 200,
            source: 'reward_redemption',
            description: 'Redeemed reward',
        );

        $this->assertEquals('spent', $transaction->type);
        $this->assertEquals(-200, $transaction->points);

        $points = LoyaltyPoints::where('user_id', $this->user->id)->first();
        $this->assertEquals(300, $points->balance);
        $this->assertEquals(200, $points->lifetime_spent);
    }

    public function test_spend_points_throws_when_insufficient_balance(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance' => 50,
                'lifetime_earned' => 50,
                'lifetime_spent' => 0,
            ]
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->service->spendPoints(
            user: $this->user,
            points: 100,
            source: 'test',
        );
    }

    public function test_convert_to_credits_spends_points_and_returns_result(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance' => 1000,
                'lifetime_earned' => 1000,
                'lifetime_spent' => 0,
            ]
        );

        $result = $this->service->convertToCredits($this->user, 100);

        $this->assertArrayHasKey('points_spent', $result);
        $this->assertArrayHasKey('credits_earned', $result);
        $this->assertEquals(100, $result['points_spent']);
    }

    public function test_get_balance_returns_correct_data(): void
    {
        LoyaltyPoints::updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'balance' => 750,
                'lifetime_earned' => 1200,
                'lifetime_spent' => 450,
                'current_multiplier' => 1.5,
            ]
        );

        $balance = $this->service->getBalance($this->user);

        $this->assertEquals(750, $balance['balance']);
        $this->assertEquals(1200, $balance['lifetime_earned']);
        $this->assertEquals(450, $balance['lifetime_spent']);
        $this->assertEquals(1.5, $balance['current_multiplier']);
    }

    public function test_get_balance_creates_record_if_missing(): void
    {
        $balance = $this->service->getBalance($this->user);

        $this->assertEquals(0, $balance['balance']);
        $this->assertDatabaseHas('loyalty_points', ['user_id' => $this->user->id]);
    }
}
