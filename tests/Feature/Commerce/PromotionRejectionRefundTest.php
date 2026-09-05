<?php

namespace Tests\Feature\Commerce;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Services\Accounts\CapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * These assert that the buyer's money moves, not that a status column changed.
 *
 * rejectCompletionById used to stamp payment_status = PAYMENT_REFUNDED and
 * then call reverseOrder(), which refunds only while that status is NOT yet
 * refunded. The order read as refunded and the buyer was never paid back —
 * a status-only assertion would have passed throughout.
 */
class PromotionRejectionRefundTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: User, 1: User, 2: Order, 3: OrderItem} */
    private function buildPaidPromotionOrder(string $verificationStatus = 'submitted'): array
    {
        $promoter = User::factory()->create();
        $buyer = User::factory()->create(['ugx_balance' => 0]);
        $store = Store::factory()->create(['user_id' => $promoter->id]);

        // /api/promotions/orders/* is gated on the promoter capability.
        app(CapabilityService::class)->grant($promoter, Capability::Promoter);

        $promotion = Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'TikTok Promo Package',
            'slug' => 'tiktok-promo-'.uniqid(),
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_ACTIVE,
            'price_ugx' => 50000,
            'price_credits' => 0,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'store_id' => $store->id,
            'user_id' => $buyer->id,
            'status' => Order::STATUS_PROCESSING,
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
            'verification_status' => $verificationStatus,
        ]);

        return [$promoter, $buyer, $order, $item];
    }

    public function test_rejecting_an_order_returns_the_money_to_the_buyer(): void
    {
        [$promoter, $buyer, $order] = $this->buildPaidPromotionOrder();

        $this->actingAs($promoter)
            ->postJson("/api/promotions/orders/{$order->id}/reject", [
                'reason' => 'Proof does not show the agreed placement.',
            ])
            ->assertOk();

        $this->assertEquals(
            50000,
            (float) $buyer->fresh()->ugx_balance,
            'The buyer must be refunded when the seller rejects the order.'
        );

        $this->assertSame(Order::PAYMENT_REFUNDED, $order->fresh()->payment_status);
    }

    public function test_an_order_cannot_be_refunded_twice(): void
    {
        [$promoter, $buyer, $order] = $this->buildPaidPromotionOrder();

        $payload = ['reason' => 'Proof does not show the agreed placement.'];

        $this->actingAs($promoter)
            ->postJson("/api/promotions/orders/{$order->id}/reject", $payload)
            ->assertOk();

        $this->actingAs($promoter)
            ->postJson("/api/promotions/orders/{$order->id}/reject", $payload)
            ->assertStatus(422);

        $this->assertEquals(
            50000,
            (float) $buyer->fresh()->ugx_balance,
            'A second rejection must not refund the buyer again.'
        );
    }

    public function test_payout_is_refused_until_the_buyer_submits_proof(): void
    {
        [$promoter, , $order] = $this->buildPaidPromotionOrder('pending');

        $this->actingAs($promoter)
            ->postJson("/api/promotions/orders/{$order->id}/verify")
            ->assertStatus(422);
    }

    public function test_payout_is_refused_while_a_dispute_is_open(): void
    {
        [$promoter, , $order, $item] = $this->buildPaidPromotionOrder();

        $item->forceFill([
            'dispute_reason' => 'The post was taken down after an hour.',
            'product_snapshot' => ['promotion_dispute' => ['state' => 'open']],
        ])->save();

        $this->actingAs($promoter)
            ->postJson("/api/promotions/orders/{$order->id}/verify")
            ->assertStatus(422);
    }
}
