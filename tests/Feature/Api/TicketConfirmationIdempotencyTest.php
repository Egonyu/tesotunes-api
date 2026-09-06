<?php

namespace Tests\Feature\Api;

use App\Events\TicketPurchased;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One ticket must produce one confirmation.
 *
 * Payment::markCompleted() (through its polymorphic payable) and
 * EventTicketingService::settlePendingOrderPayment() both confirm the attendee
 * when a payment completes. Neither checked whether the other had already run,
 * so TicketPurchased fired twice: the buyer saw two identical confirmations in
 * the bell and the platform attempted two confirmation emails per ticket.
 */
class TicketConfirmationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function attendee(float $pricePaid = 5000): EventAttendee
    {
        $buyer = User::factory()->create();
        $event = Event::factory()->published()->create(['starts_at' => now()->addWeek()]);

        $tier = EventTicket::query()->create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Ordinary',
            'price_ugx' => $pricePaid,
            'price_credits' => 0,
            'is_free' => false,
            'is_active' => true,
            'quantity_total' => 100,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addWeek(),
        ]);

        return EventAttendee::query()->create([
            'uuid' => (string) Str::uuid(),
            'confirmation_code' => 'TKT-'.strtoupper(Str::random(8)),
            'event_id' => $event->id,
            'ticket_id' => $tier->id,
            'user_id' => $buyer->id,
            'attendee_name' => $buyer->name,
            'attendee_email' => $buyer->email,
            'price_paid_ugx' => $pricePaid,
            'payment_method' => 'mtn_momo',
            'status' => EventAttendee::STATUS_PENDING ?? 'pending',
        ]);
    }

    public function test_confirming_twice_announces_the_purchase_once(): void
    {
        EventFacade::fake([TicketPurchased::class]);
        $attendee = $this->attendee();

        // Both settlement paths run for the same completed payment.
        $attendee->confirm('PAY-REF-1');
        $attendee->confirm('PAY-REF-1');

        EventFacade::assertDispatchedTimes(TicketPurchased::class, 1);
    }

    public function test_the_first_confirmation_still_issues_the_ticket(): void
    {
        EventFacade::fake([TicketPurchased::class]);
        $attendee = $this->attendee();

        $attendee->confirm('PAY-REF-1');

        $attendee->refresh();
        $this->assertSame(EventAttendee::STATUS_CONFIRMED, $attendee->status);
        $this->assertNotNull($attendee->confirmed_at);
        $this->assertSame('completed', $attendee->payment_status);
        $this->assertSame('PAY-REF-1', $attendee->payment_reference);
        EventFacade::assertDispatched(TicketPurchased::class);
    }

    public function test_a_reference_supplied_by_the_later_path_is_still_recorded(): void
    {
        // Only one of the two callers passes a payment reference, and the order
        // they arrive in is not guaranteed.
        EventFacade::fake([TicketPurchased::class]);
        $attendee = $this->attendee();

        $attendee->confirm();
        $attendee->confirm('PAY-REF-LATE');

        $attendee->refresh();
        $this->assertSame('PAY-REF-LATE', $attendee->payment_reference);
        EventFacade::assertDispatchedTimes(TicketPurchased::class, 1);
    }

    public function test_a_free_ticket_never_announces_a_purchase(): void
    {
        EventFacade::fake([TicketPurchased::class]);
        $attendee = $this->attendee(0);

        $attendee->confirm();

        EventFacade::assertNotDispatched(TicketPurchased::class);
    }
}
