<?php

namespace Tests\Feature\Commerce;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Services\Accounts\CapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Purchase had no replay protection: a double-tapped button or a client retry
 * after a timeout created a second order and took a second debit. Buyers pay
 * for promotions in credits they earned, so a duplicate charge is not a
 * cosmetic problem.
 */
class PromotionPurchaseIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private function promotionCosting(int $credits): Product
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
            'price_ugx' => 0,
            'allow_credit_payment' => true,
            'accepts_credits' => true,
            'is_active' => true,
        ]);
    }

    public function test_replaying_a_purchase_with_the_same_key_returns_the_first_order(): void
    {
        $promotion = $this->promotionCosting(500);
        $buyer = User::factory()->create();
        $buyer->addCredits(2000, 'test', 'seed');

        $payload = ['payment_method' => 'credits', 'idempotency_key' => 'checkout-abc-123'];

        $first = $this->actingAs($buyer)
            ->postJson("/api/promotions/{$promotion->slug}/purchase", $payload)
            ->assertCreated();

        $second = $this->actingAs($buyer)
            ->postJson("/api/promotions/{$promotion->slug}/purchase", $payload)
            ->assertOk();

        $this->assertSame($first->json('order_id'), $second->json('order_id'));
        $this->assertTrue($second->json('idempotent_replay'));

        $this->assertSame(1, Order::query()->where('user_id', $buyer->id)->count());
        $this->assertEquals(
            1500,
            (float) $buyer->fresh()->creditWallet->balance,
            'The replay must not take a second 500-credit debit.'
        );
    }

    public function test_a_different_key_is_a_genuine_second_purchase(): void
    {
        $promotion = $this->promotionCosting(500);
        $buyer = User::factory()->create();
        $buyer->addCredits(2000, 'test', 'seed');

        $this->actingAs($buyer)->postJson("/api/promotions/{$promotion->slug}/purchase", [
            'payment_method' => 'credits',
            'idempotency_key' => 'checkout-one',
        ])->assertCreated();

        $this->actingAs($buyer)->postJson("/api/promotions/{$promotion->slug}/purchase", [
            'payment_method' => 'credits',
            'idempotency_key' => 'checkout-two',
        ])->assertCreated();

        $this->assertSame(2, Order::query()->where('user_id', $buyer->id)->count());
        $this->assertEquals(1000, (float) $buyer->fresh()->creditWallet->balance);
    }

    public function test_the_key_is_scoped_to_the_buyer(): void
    {
        $promotion = $this->promotionCosting(500);

        $first = User::factory()->create();
        $first->addCredits(2000, 'test', 'seed');
        $second = User::factory()->create();
        $second->addCredits(2000, 'test', 'seed');

        $payload = ['payment_method' => 'credits', 'idempotency_key' => 'shared-key'];

        $this->actingAs($first)->postJson("/api/promotions/{$promotion->slug}/purchase", $payload)->assertCreated();
        $this->actingAs($second)->postJson("/api/promotions/{$promotion->slug}/purchase", $payload)->assertCreated();

        $this->assertSame(1, Order::query()->where('user_id', $first->id)->count());
        $this->assertSame(1, Order::query()->where('user_id', $second->id)->count());
    }

    public function test_purchases_without_a_key_still_work(): void
    {
        $promotion = $this->promotionCosting(500);
        $buyer = User::factory()->create();
        $buyer->addCredits(2000, 'test', 'seed');

        $this->actingAs($buyer)
            ->postJson("/api/promotions/{$promotion->slug}/purchase", ['payment_method' => 'credits'])
            ->assertCreated();

        $this->assertSame(1, Order::query()->where('user_id', $buyer->id)->count());
    }
}
