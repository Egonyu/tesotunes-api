<?php

namespace Tests\Feature\Commerce;

use App\Models\Commerce\Settlement;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Payment;
use App\Models\User;
use App\Services\Events\EventTicketingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EventTicketSettlementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_wallet_ticket_purchase_settles_proceeds_to_event_organizer(): void
    {
        $organizer = User::factory()->create();
        $buyer = User::factory()->create(['ugx_balance' => 200000, 'credits' => 0]);
        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'ends_at' => now()->addDays(10),
        ]);
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 20000,
            'price_credits' => 0,
            'quantity_total' => 10,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($buyer)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
            'payment_method' => 'wallet',
            'holder_name' => 'Wallet Buyer',
            'holder_email' => $buyer->email,
        ]);

        $response->assertCreated();

        $settlement = Settlement::query()
            ->where('beneficiary_user_id', $organizer->id)
            ->where('vertical', Settlement::VERTICAL_EVENTS)
            ->where('kind', 'ticket_sale')
            ->first();

        $this->assertNotNull($settlement, 'completed ticket purchase must settle to the organizer');
        $this->assertSame(Settlement::STATUS_PENDING, $settlement->status);

        // Net must equal the fee calculator's organizer_net_amount: gross - fees.
        $this->assertEqualsWithDelta(
            (float) $settlement->gross_ugx - (float) $settlement->fee_ugx,
            (float) $settlement->net_ugx,
            0.001
        );
        $this->assertGreaterThan(0, (float) $settlement->net_ugx);

        // Funds are held until the event ends.
        $this->assertTrue($settlement->hold_until->equalTo($event->ends_at));
    }

    public function test_mobile_money_confirmation_settles_once_even_when_replayed(): void
    {
        $organizer = User::factory()->create();
        $buyer = User::factory()->create();
        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
            'ends_at' => now()->addDays(5),
        ]);
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Regular',
            'price_ugx' => 10000,
            'price_credits' => 0,
            'quantity_total' => 50,
            'quantity_sold' => 0,
            'quantity_reserved' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $reference = 'EVT-TEST-'.uniqid();
        $payment = Payment::factory()->create([
            'user_id' => $buyer->id,
            'payment_type' => 'ticket_purchase',
            'payment_reference' => $reference,
            'status' => Payment::STATUS_PENDING,
            'metadata' => ['ticket_id' => $ticket->id],
            'payment_data' => [],
        ]);

        $feeBreakdown = [
            'quantity' => 1,
            'base_amount' => 10000.0,
            'discounted_base_amount' => 10000.0,
            'total_fee_amount' => 1500.0,
            'organizer_net_amount' => 8500.0,
            'total_credits' => 0.0,
        ];

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-MOMO-1',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Momo Buyer',
            'attendee_email' => $buyer->email,
            'status' => EventAttendee::STATUS_PENDING,
            'payment_method' => 'mtn_momo',
            'payment_reference' => $reference,
            'payment_status' => 'pending',
            'attendee_metadata' => ['fee_breakdown' => $feeBreakdown, 'payment_id' => $payment->id],
        ]);

        $service = app(EventTicketingService::class);
        $service->settlePendingOrderPayment($payment);
        $service->settlePendingOrderPayment($payment->fresh());

        $settlements = Settlement::query()
            ->where('beneficiary_user_id', $organizer->id)
            ->where('source_type', $payment->getMorphClass())
            ->where('source_id', $payment->id)
            ->get();

        $this->assertCount(1, $settlements, 'webhook replay must not double-settle');
        $this->assertSame('8500.00', (string) $settlements->first()->net_ugx);
        $this->assertSame(
            EventAttendee::STATUS_CONFIRMED,
            EventAttendee::where('payment_reference', $reference)->first()->status
        );
    }
}
