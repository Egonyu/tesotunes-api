<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Escrow used to have no way out but the seller. A promoter who submitted
 * proof and then heard nothing stayed unpaid forever, with the buyer's
 * credits already debited — config('promotions.auto_release_hours') described
 * a 7-day window that nothing read.
 */
class PromotionEscrowAutoReleaseTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: User, 1: Order, 2: OrderItem} */
    private function submittedOrder(int $submittedHoursAgo): array
    {
        $promoter = User::factory()->create();
        $buyer = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $promoter->id]);

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
            'verification_status' => 'submitted',
            'verification_submitted_at' => now()->subHours($submittedHoursAgo),
        ]);

        return [$promoter, $order, $item];
    }

    public function test_proof_older_than_the_window_is_released_to_the_promoter(): void
    {
        [, $order, $item] = $this->submittedOrder(submittedHoursAgo: 200);

        $this->artisan('promotions:release-due-escrow')->assertSuccessful();

        $this->assertSame('verified', $item->fresh()->verification_status);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNull(
            $item->fresh()->verified_by,
            'An automatic release records no verifying user, which is how it is told apart from a seller acceptance.'
        );
    }

    public function test_proof_inside_the_window_is_left_alone(): void
    {
        [, $order, $item] = $this->submittedOrder(submittedHoursAgo: 24);

        $this->artisan('promotions:release-due-escrow')->assertSuccessful();

        $this->assertSame('submitted', $item->fresh()->verification_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
    }

    public function test_an_order_with_an_open_dispute_is_never_auto_released(): void
    {
        [, $order, $item] = $this->submittedOrder(submittedHoursAgo: 200);

        $item->forceFill([
            'dispute_reason' => 'The post was taken down after an hour.',
            'product_snapshot' => ['promotion_dispute' => ['state' => 'open']],
        ])->save();

        $this->artisan('promotions:release-due-escrow')->assertSuccessful();

        $this->assertSame('submitted', $item->fresh()->verification_status);
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        [, , $item] = $this->submittedOrder(submittedHoursAgo: 200);

        $this->artisan('promotions:release-due-escrow', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('submitted', $item->fresh()->verification_status);
    }
}
