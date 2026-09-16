<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Notifications\CrossModuleNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A promoter's life from onboarding to money in the wallet — September 2026.
 *
 * - The promoter proves delivery and the buyer accepts. It used to be the
 *   other way round, so a promoter could release their own payment.
 * - Cleared settlements used to stop in the ledger: the dashboard showed
 *   "available" earnings that withdrawal could never reach.
 */
class PromoterLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: User, 1: Product} */
    private function onboardedPromoterWithService(): array
    {
        $promoter = User::factory()->create(['ugx_balance' => 0]);

        $this->actingAs($promoter)->postJson('/api/promoters/onboard', [
            'display_name' => 'Soroti Vibes',
            'platforms' => ['tiktok'],
            'niches' => ['Afrobeats'],
            'audience_regions' => ['Uganda'],
        ])->assertSuccessful();

        $this->assertTrue($promoter->fresh()->hasCapability(\App\Enums\Capability::Promoter));

        $store = Store::query()->where('user_id', $promoter->id)->firstOrFail();

        $service = Product::create([
            'uuid' => (string) \Str::uuid(),
            'store_id' => $store->id,
            'name' => 'TikTok Feature',
            'slug' => 'tiktok-feature-'.uniqid(),
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_ACTIVE,
            'price_ugx' => 20000,
            'price_credits' => 0,
            'is_active' => true,
        ]);

        return [$promoter->fresh(), $service];
    }

    private function purchase(Product $service): array
    {
        $buyer = User::factory()->create(['ugx_balance' => 50000]);

        $orderId = $this->actingAs($buyer)
            ->postJson("/api/promotions/{$service->slug}/purchase", ['payment_method' => 'ugx'])
            ->assertCreated()
            ->json('order_id');

        return [$buyer, Order::findOrFail($orderId)];
    }

    public function test_a_promoter_is_paid_into_their_wallet_after_the_buyer_accepts(): void
    {
        Notification::fake();

        [$promoter, $service] = $this->onboardedPromoterWithService();
        [$buyer, $order] = $this->purchase($service);

        $this->assertEquals(30000, (float) $buyer->fresh()->ugx_balance, 'Buyer is charged into escrow.');

        // A merch order must not show up among promotion purchases.
        \App\Modules\Store\Models\Order::factory()->create(['user_id' => $buyer->id]);
        $this->actingAs($buyer)->getJson('/api/my/promotions/purchases')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($promoter)->getJson('/api/my/promotions/analytics')
            ->assertOk()
            ->assertJsonPath('data.awaiting_delivery', 1)
            ->assertJsonPath('data.escrow_ugx', fn ($value) => $value > 0)
            ->assertJsonPath('data.net_revenue_ugx', 0);

        $this->actingAs($buyer)->postJson("/api/promotions/orders/{$order->id}/accept")
            ->assertStatus(422);

        $this->actingAs($promoter)->postJson("/api/promotions/orders/{$order->id}/deliver", [
            'delivery_url' => 'https://www.tiktok.com/@sorotivibes/video/1',
            'delivery_notes' => 'Posted at 8 PM.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verification_submitted')
            ->assertJsonPath('data.verification.verification_url', 'https://www.tiktok.com/@sorotivibes/video/1');

        $this->actingAs($buyer)->getJson("/api/my/promotions/purchases/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.verification.status', 'submitted');

        $this->actingAs($buyer)->postJson("/api/promotions/orders/{$order->id}/accept")->assertOk();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);

        $settlement = Settlement::query()->where('beneficiary_user_id', $promoter->id)->firstOrFail();
        $this->assertSame(Settlement::STATUS_PENDING, $settlement->status);
        $settlement->forceFill(['hold_until' => now()->subMinute()])->save();

        $this->artisan('commerce:clear-due-settlements')->assertSuccessful();

        $settlement->refresh();
        $this->assertSame(Settlement::STATUS_PAID_OUT, $settlement->status);
        $this->assertEquals((float) $settlement->net_ugx, (float) $promoter->fresh()->ugx_balance);
        $this->assertGreaterThan(0, (float) $settlement->net_ugx);

        $this->actingAs($promoter)->getJson('/api/payments/wallet/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.payment_type', 'earnings_payout');

        // Each side is told when it is their turn.
        foreach ([[$promoter, 'order_received'], [$buyer, 'promotion_delivered'], [$promoter, 'promotion_accepted']] as [$recipient, $type]) {
            Notification::assertSentTo(
                $recipient,
                CrossModuleNotification::class,
                fn (CrossModuleNotification $notification) => $notification->toArray($recipient)['type'] === $type,
            );
        }

        // A second run pays nothing more.
        $this->artisan('commerce:clear-due-settlements')->assertSuccessful();
        $this->assertEquals((float) $settlement->net_ugx, (float) $promoter->fresh()->ugx_balance);

        // Paid out: a dispute can no longer hold the money back.
        $this->actingAs($buyer)->postJson("/api/promotions/orders/{$order->id}/dispute", [
            'reason' => 'Changed my mind.',
        ])->assertStatus(422);
    }

    public function test_a_promoter_cannot_release_their_own_payment(): void
    {
        [$promoter, $service] = $this->onboardedPromoterWithService();
        [, $order] = $this->purchase($service);

        $this->actingAs($promoter)->postJson("/api/promotions/orders/{$order->id}/deliver", [
            'delivery_url' => 'https://www.tiktok.com/@sorotivibes/video/1',
        ])->assertOk();

        $this->actingAs($promoter)->getJson('/api/my/promotions/orders?status=verification_submitted')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($promoter)->getJson('/api/my/promotions/orders?status=completed')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($promoter)->postJson("/api/promotions/orders/{$order->id}/accept")->assertNotFound();
        $this->assertContains(
            $this->actingAs($promoter)->postJson("/api/promotions/orders/{$order->id}/verify")->status(),
            [404, 405],
            'The promoter self-release route is gone.',
        );

        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertSame(0, Settlement::query()->where('beneficiary_user_id', $promoter->id)->count());
    }

    public function test_a_buyer_cannot_submit_delivery_proof(): void
    {
        [, $service] = $this->onboardedPromoterWithService();
        [$buyer, $order] = $this->purchase($service);

        $this->actingAs($buyer)->postJson("/api/promotions/orders/{$order->id}/deliver", [
            'delivery_url' => 'https://example.com/post',
        ])->assertForbidden();
    }

    public function test_a_disputed_delivery_is_not_paid(): void
    {
        [$promoter, $service] = $this->onboardedPromoterWithService();
        [$buyer, $order] = $this->purchase($service);

        $this->actingAs($promoter)->postJson("/api/promotions/orders/{$order->id}/deliver", [
            'delivery_url' => 'https://www.tiktok.com/@sorotivibes/video/1',
        ])->assertOk();

        $this->actingAs($buyer)->postJson("/api/promotions/orders/{$order->id}/dispute", [
            'reason' => 'The video was deleted an hour later.',
            'reason_code' => 'missing_delivery',
        ])->assertOk();

        $this->actingAs($buyer)->postJson("/api/promotions/orders/{$order->id}/accept")->assertStatus(422);
        $this->assertSame(0, Settlement::query()->where('beneficiary_user_id', $promoter->id)->count());
    }

    public function test_a_refunded_sale_is_reversed_instead_of_paid(): void
    {
        $seller = User::factory()->create(['ugx_balance' => 0]);
        $store = Store::factory()->create(['user_id' => $seller->id]);
        $order = Order::factory()->create([
            'store_id' => $store->id,
            'status' => Order::STATUS_CANCELLED,
            'payment_status' => Order::PAYMENT_REFUNDED,
        ]);

        $settlement = app(\App\Services\Commerce\SettlementService::class)->record(
            beneficiary: $seller,
            source: $order,
            vertical: Settlement::VERTICAL_STORE,
            kind: 'sale',
            amounts: ['gross_ugx' => 10000, 'fee_ugx' => 1000],
        );
        $settlement->forceFill(['status' => Settlement::STATUS_CLEARED])->save();

        $this->artisan('commerce:clear-due-settlements')->assertSuccessful();

        $this->assertSame(Settlement::STATUS_REVERSED, $settlement->fresh()->status);
        $this->assertEquals(0, (float) $seller->fresh()->ugx_balance);
        $this->assertSame(0, Payment::query()->where('user_id', $seller->id)->count());
    }

    public function test_ticket_money_waits_until_the_event_has_happened(): void
    {
        $organizer = User::factory()->create(['ugx_balance' => 0]);
        $event = \App\Models\Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(5),
        ]);
        $payment = Payment::factory()->create(['user_id' => User::factory()->create()->id]);
        $payment->forceFill(['status' => Payment::STATUS_COMPLETED])->save();

        $settlement = app(\App\Services\Commerce\SettlementService::class)->record(
            beneficiary: $organizer,
            source: $payment,
            vertical: Settlement::VERTICAL_EVENTS,
            kind: 'ticket_sale',
            amounts: ['gross_ugx' => 5000],
            metadata: ['event_id' => $event->id],
        );
        $settlement->forceFill(['status' => Settlement::STATUS_CLEARED])->save();

        $this->artisan('commerce:clear-due-settlements')->assertSuccessful();
        $this->assertSame(Settlement::STATUS_CLEARED, $settlement->fresh()->status, 'Not before the show.');
        $this->assertEquals(0, (float) $organizer->fresh()->ugx_balance);

        $event->forceFill(['starts_at' => now()->subHours(6), 'ends_at' => now()->subHour()])->saveQuietly();

        $this->artisan('commerce:clear-due-settlements')->assertSuccessful();
        $this->assertSame(Settlement::STATUS_PAID_OUT, $settlement->fresh()->status);
        $this->assertEquals(5000, (float) $organizer->fresh()->ugx_balance);
    }

    public function test_music_earnings_are_never_paid_twice_through_the_wallet(): void
    {
        $artist = User::factory()->create(['ugx_balance' => 0]);
        $settlement = Settlement::factory()->cleared()->create([
            'beneficiary_user_id' => $artist->id,
            'vertical' => Settlement::VERTICAL_MUSIC,
        ]);

        $this->artisan('commerce:clear-due-settlements')->assertSuccessful();

        $this->assertSame(Settlement::STATUS_CLEARED, $settlement->fresh()->status);
        $this->assertEquals(0, (float) $artist->fresh()->ugx_balance);
    }
}
