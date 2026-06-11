<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PromotionSettlementLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private function buildPromotionOrder(): array
    {
        $promoter = User::factory()->create();
        $buyer = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $promoter->id]);
        $promotion = Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'TikTok Promo Package',
            'slug' => 'tiktok-promo-'.uniqid(),
            'product_type' => 'promotion',
            'status' => 'active',
            'price_ugx' => 50000,
            'price_credits' => 0,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $store->id,
            'user_id' => $buyer->id,
            'payment_status' => Order::PAYMENT_PAID,
            'paid_ugx' => 50000,
            'paid_credits' => 0,
            'total_ugx' => 50000,
            'total_credits' => 0,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $promotion->id,
            'price_ugx' => 50000,
            'price_credits' => 0,
            'verification_status' => 'verified',
        ]);

        return [$promoter, $order, $item];
    }

    public function test_proof_acceptance_settles_promoter_proceeds_in_the_ledger(): void
    {
        [$promoter, $order, $item] = $this->buildPromotionOrder();

        $service = app(PromotionSettlementService::class);
        $service->settleOrder($order, $item);
        $service->settleOrder($order, $item->fresh());

        $settlements = Settlement::query()
            ->where('beneficiary_user_id', $promoter->id)
            ->where('vertical', Settlement::VERTICAL_PROMOTIONS)
            ->where('kind', 'promo_service')
            ->get();

        $this->assertCount(1, $settlements, 'verification replay must not double-settle');

        $settlement = $settlements->first();
        $this->assertSame(Settlement::STATUS_PENDING, $settlement->status);
        $this->assertEqualsWithDelta(50000.0, (float) $settlement->gross_ugx, 0.001);
        $this->assertEqualsWithDelta(
            (float) $settlement->gross_ugx - (float) $settlement->fee_ugx,
            (float) $settlement->net_ugx,
            0.001
        );
        $this->assertNotNull($settlement->hold_until, 'promotions vertical has a dispute hold');
    }

    public function test_dispute_reversal_reverses_the_ledger_settlement(): void
    {
        [$promoter, $order, $item] = $this->buildPromotionOrder();

        $service = app(PromotionSettlementService::class);
        $service->settleOrder($order, $item);
        $service->reverseOrder($order->fresh(), $item->fresh(), 'dispute upheld');

        $settlement = Settlement::query()
            ->where('beneficiary_user_id', $promoter->id)
            ->where('kind', 'promo_service')
            ->first();

        $this->assertSame(Settlement::STATUS_REVERSED, $settlement->status);
        $this->assertSame('dispute upheld', $settlement->reversal_reason);
    }
}
