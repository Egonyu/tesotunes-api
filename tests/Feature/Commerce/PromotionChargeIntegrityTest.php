<?php

namespace Tests\Feature\Commerce;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Services\Accounts\CapabilityService;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Both promotion purchase paths called spendCredits() and threw the result
 * away. UserCredit::spendCredits() locks the wallet, re-checks, and returns
 * null rather than throwing when the balance moved — so the order was still
 * created and marked PAID with no debit behind it. The buyer got the
 * promotion free and the promoter was owed money nobody had taken.
 *
 * The UGX leg failed from the other direction: a bare decrement with no
 * floor, which simply went negative.
 */
class PromotionChargeIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private function activePromotion(int $credits, float $ugx): Product
    {
        $seller = User::factory()->create();
        app(CapabilityService::class)->grant($seller, Capability::Promoter);
        $store = Store::factory()->create(['user_id' => $seller->id]);

        return Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'Tiktok Boost',
            'slug' => 'tiktok-boost-'.uniqid(),
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_ACTIVE,
            'price_credits' => $credits,
            'price_ugx' => $ugx,
            'allow_credit_payment' => true,
            'accepts_credits' => true,
            'is_active' => true,
        ]);
    }

    /**
     * The pre-check refuses this one before the transaction opens, so it
     * covers the friendly path only — not the debit. The race it cannot
     * reach is covered by the rollback test below.
     */
    public function test_an_obviously_unaffordable_ugx_purchase_is_refused_up_front(): void
    {
        $promotion = $this->activePromotion(credits: 0, ugx: 50000);
        $buyer = User::factory()->create(['ugx_balance' => 10000]);

        $this->actingAs($buyer)
            ->postJson("/api/promotions/{$promotion->slug}/purchase", ['payment_method' => 'ugx'])
            ->assertStatus(422);

        $this->assertEquals(10000, (float) $buyer->fresh()->ugx_balance);
        $this->assertSame(0, Order::query()->where('user_id', $buyer->id)->count());
    }

    /**
     * Drives the case the pre-check waves through and the debit rejects.
     *
     * The pre-check reads creditWallet->available_credits and falls back to
     * the users.credits column when no wallet row exists. An account with a
     * populated column and no wallet row therefore passes the check, then
     * ensureCreditWallet() creates the wallet at zero and the spend fails.
     * That divergence is real — it is the same column-versus-row split that
     * produced the empty-wallet backfill.
     *
     * Before chargeBuyer() this produced a PAID order with no debit: the
     * buyer got the promotion free and the promoter was owed money nobody
     * had taken. The order must not survive.
     */
    public function test_a_failed_debit_rolls_the_whole_order_back(): void
    {
        $promotion = $this->activePromotion(credits: 500, ugx: 0);

        $buyer = User::factory()->create();
        $buyer->forceFill(['credits' => 5_000])->save();
        $this->assertNull($buyer->creditWallet, 'The wallet row must be absent for the pre-check to fall through.');

        $this->actingAs($buyer)
            ->postJson("/api/promotions/{$promotion->slug}/purchase", ['payment_method' => 'credits'])
            ->assertStatus(422);

        $this->assertSame(
            0,
            Order::query()->where('user_id', $buyer->id)->count(),
            'A refused charge must leave no order behind.'
        );
        $this->assertEquals(0, (float) $buyer->fresh()->ensureCreditWallet()->balance);
    }

    public function test_charging_more_credits_than_the_wallet_holds_is_refused(): void
    {
        $buyer = User::factory()->create();
        $buyer->ensureCreditWallet();

        $this->expectException(\RuntimeException::class);

        app(PromotionSettlementService::class)->chargeBuyer(
            $buyer,
            credits: 5_000,
            ugx: 0,
            source: 'promotion_purchase',
            description: 'test charge'
        );
    }

    public function test_charging_more_ugx_than_the_wallet_holds_is_refused_and_leaves_it_untouched(): void
    {
        $buyer = User::factory()->create(['ugx_balance' => 1000]);

        try {
            app(PromotionSettlementService::class)->chargeBuyer(
                $buyer,
                credits: 0,
                ugx: 5000,
                source: 'promotion_purchase',
                description: 'test charge'
            );
            $this->fail('Expected the charge to be refused.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertEquals(1000, (float) $buyer->fresh()->ugx_balance);
    }

    public function test_a_charge_the_buyer_can_cover_debits_exactly_once(): void
    {
        $buyer = User::factory()->create(['ugx_balance' => 5000]);

        app(PromotionSettlementService::class)->chargeBuyer(
            $buyer,
            credits: 0,
            ugx: 2000,
            source: 'promotion_purchase',
            description: 'test charge'
        );

        $this->assertEquals(3000, (float) $buyer->fresh()->ugx_balance);
    }
}
