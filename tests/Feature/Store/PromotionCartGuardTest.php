<?php

namespace Tests\Feature\Store;

use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Promotions live in store_products so they inherit orders, payments,
 * settlement, reviews and disputes. The cart was the one shared path never
 * taught the difference: it would happily take a promotion, price it from
 * price_ugx alone (zero for a credits-priced gig), and check it out through
 * OrderController — which knows nothing about settlement snapshots or the
 * proof lifecycle. The result is an order the promoter can never be paid for
 * and the buyer can never dispute.
 */
class PromotionCartGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function promotion(): Product
    {
        $store = Store::factory()->create(['user_id' => User::factory()->create()->id]);

        return Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'Tiktok Boost',
            'slug' => 'tiktok-boost-'.uniqid(),
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_ACTIVE,
            'price_ugx' => 0,
            'price_credits' => 500,
            'is_active' => true,
        ]);
    }

    private function merch(): Product
    {
        $store = Store::factory()->create(['user_id' => User::factory()->create()->id]);

        return Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'Tour Tee',
            'slug' => 'tour-tee-'.uniqid(),
            'product_type' => Product::TYPE_PHYSICAL,
            'status' => Product::STATUS_ACTIVE,
            'price_ugx' => 45000,
            'price_credits' => 0,
            'is_active' => true,
            // store_products.track_inventory defaults to 1 with 0 on hand, so
            // stock has to be stated or the cart refuses this for a reason
            // that has nothing to do with what is under test.
            'track_inventory' => false,
        ]);
    }

    public function test_a_promotion_cannot_be_added_to_the_cart(): void
    {
        $promotion = $this->promotion();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/store/cart/items', [
                'product_id' => $promotion->id,
                'quantity' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_ordinary_products_are_unaffected(): void
    {
        $merch = $this->merch();

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/store/cart/items', [
                'product_id' => $merch->id,
                'quantity' => 1,
            ]);

        $this->assertNotSame(422, $response->status());
    }

    public function test_the_cart_service_refuses_a_promotion_directly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CartService::class)->addItem($this->promotion());
    }

    public function test_the_model_states_which_types_the_cart_can_complete(): void
    {
        $this->assertFalse($this->promotion()->isCartCheckoutable());
        $this->assertTrue($this->merch()->isCartCheckoutable());
    }
}
