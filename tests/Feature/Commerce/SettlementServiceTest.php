<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\Payment;
use App\Models\Song;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\PaymentService;
use App\Services\Commerce\SettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SettlementServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SettlementService $settlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settlements = app(SettlementService::class);
    }

    public function test_record_creates_pending_settlement_with_computed_net(): void
    {
        $beneficiary = User::factory()->create();
        $source = Song::factory()->create();

        $settlement = $this->settlements->record(
            beneficiary: $beneficiary,
            source: $source,
            vertical: Settlement::VERTICAL_STORE,
            kind: 'sale',
            amounts: ['gross_ugx' => 10000, 'fee_ugx' => 3000, 'gross_credits' => 50, 'fee_credits' => 15],
        );

        $this->assertSame(Settlement::STATUS_PENDING, $settlement->status);
        $this->assertSame('7000.00', (string) $settlement->net_ugx);
        $this->assertSame(35, $settlement->net_credits);
        $this->assertNotNull($settlement->hold_until, 'store vertical has a default dispute hold');
        $this->assertNotNull($settlement->uuid);
    }

    public function test_record_is_idempotent_per_source_beneficiary_and_kind(): void
    {
        $beneficiary = User::factory()->create();
        $source = Song::factory()->create();
        $amounts = ['gross_ugx' => 5000, 'fee_ugx' => 1500];

        $first = $this->settlements->record($beneficiary, $source, Settlement::VERTICAL_STORE, 'sale', $amounts);
        $second = $this->settlements->record($beneficiary, $source, Settlement::VERTICAL_STORE, 'sale', $amounts);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Settlement::query()->where('beneficiary_user_id', $beneficiary->id)->count());
    }

    public function test_record_rejects_fee_exceeding_gross(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settlements->record(
            User::factory()->create(),
            Song::factory()->create(),
            Settlement::VERTICAL_STORE,
            'sale',
            ['gross_ugx' => 1000, 'fee_ugx' => 2000],
        );
    }

    public function test_clear_due_promotes_only_settlements_past_their_hold(): void
    {
        $dueNoHold = Settlement::factory()->create(['hold_until' => null]);
        $duePast = Settlement::factory()->create(['hold_until' => now()->subHour()]);
        $notDue = Settlement::factory()->create(['hold_until' => now()->addDay()]);

        $cleared = $this->settlements->clearDue();

        $this->assertSame(2, $cleared);
        $this->assertSame(Settlement::STATUS_CLEARED, $dueNoHold->fresh()->status);
        $this->assertSame(Settlement::STATUS_CLEARED, $duePast->fresh()->status);
        $this->assertSame(Settlement::STATUS_PENDING, $notDue->fresh()->status);
        $this->assertNotNull($duePast->fresh()->cleared_at);
    }

    public function test_reverse_works_from_pending_but_not_after_payout(): void
    {
        $pending = Settlement::factory()->create();

        $reversed = $this->settlements->reverse($pending, 'buyer refunded');
        $this->assertSame(Settlement::STATUS_REVERSED, $reversed->status);
        $this->assertSame('buyer refunded', $reversed->reversal_reason);

        $paidOut = Settlement::factory()->cleared()->create();
        $payout = User::factory()->create(); // any model works as a payout morph stand-in
        $this->settlements->markPaidOut([$paidOut], $payout);

        $this->expectException(\LogicException::class);
        $this->settlements->reverse($paidOut->fresh(), 'too late');
    }

    public function test_mark_paid_out_requires_cleared_status_and_links_payout(): void
    {
        $cleared = Settlement::factory()->cleared()->create();
        $payout = User::factory()->create();

        $count = $this->settlements->markPaidOut([$cleared], $payout);

        $this->assertSame(1, $count);
        $fresh = $cleared->fresh();
        $this->assertSame(Settlement::STATUS_PAID_OUT, $fresh->status);
        $this->assertSame($payout->getMorphClass(), $fresh->payout_type);
        $this->assertEquals($payout->id, $fresh->payout_id);

        $this->expectException(\LogicException::class);
        $this->settlements->markPaidOut([Settlement::factory()->create()], $payout);
    }

    public function test_balances_aggregate_net_amounts_per_status(): void
    {
        $user = User::factory()->create();

        Settlement::factory()->create([
            'beneficiary_user_id' => $user->id,
            'gross_ugx' => 10000, 'fee_ugx' => 0, 'net_ugx' => 10000,
        ]);
        Settlement::factory()->cleared()->create([
            'beneficiary_user_id' => $user->id,
            'gross_ugx' => 4000, 'fee_ugx' => 0, 'net_ugx' => 4000,
            'gross_credits' => 20, 'fee_credits' => 0, 'net_credits' => 20,
        ]);

        $balances = $this->settlements->balances($user);

        $this->assertSame(10000.0, $balances['pending']['ugx']);
        $this->assertSame(4000.0, $balances['cleared']['ugx']);
        $this->assertSame(20, $balances['cleared']['credits']);
        $this->assertSame(0.0, $balances['paid_out']['ugx']);
    }

    public function test_store_payment_confirmation_settles_proceeds_to_store_owner(): void
    {
        $owner = User::factory()->create();
        $buyer = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id]);
        $order = Order::factory()->create([
            'store_id' => $store->id,
            'user_id' => $buyer->id,
            'total_ugx' => 20000,
            'platform_fee_ugx' => 6000,
            'total_credits' => 0,
            'platform_fee_credits' => 0,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $buyer->id,
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->id,
            'payment_data' => ['ugx_amount' => 20000, 'credits_used' => 0],
        ]);

        app(PaymentService::class)->confirmPayment($payment);

        $settlement = Settlement::query()
            ->where('beneficiary_user_id', $owner->id)
            ->where('source_type', $order->getMorphClass())
            ->where('source_id', $order->id)
            ->first();

        $this->assertNotNull($settlement, 'paid store order must produce a settlement for the owner');
        $this->assertSame('14000.00', (string) $settlement->net_ugx);
        $this->assertSame(Settlement::VERTICAL_STORE, $settlement->vertical);
        $this->assertSame(Settlement::STATUS_PENDING, $settlement->status);

        // Confirming the same payment flow again must not double-settle.
        app(PaymentService::class)->confirmPayment($payment->fresh());
        $this->assertSame(1, Settlement::query()->where('source_id', $order->id)->where('source_type', $order->getMorphClass())->count());
    }
}
