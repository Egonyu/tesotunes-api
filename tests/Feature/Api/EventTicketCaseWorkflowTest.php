<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventPayoutLedgerEntry;
use App\Models\EventStaffMember;
use App\Models\EventTicket;
use App\Models\EventTicketCase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventTicketCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_owner_can_open_refund_request_case(): void
    {
        $buyer = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 50,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) Str::uuid(),
            'confirmation_code' => 'CASE-1001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Refund Buyer',
            'attendee_email' => 'refund-buyer@example.com',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
            'price_paid_ugx' => 25000,
            'amount_paid' => 25000,
            'attendee_metadata' => [
                'order_id' => 'ORD-CASE-1',
            ],
        ]);

        $response = $this->actingAs($buyer)->postJson("/api/tickets/{$attendee->id}/cases", [
            'case_type' => 'refund_request',
            'reason' => 'The event timing changed and I can no longer attend.',
            'requested_refund_amount' => 25000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.case_type', 'refund_request')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('event_ticket_cases', [
            'event_attendee_id' => $attendee->id,
            'case_type' => 'refund_request',
            'status' => 'open',
        ]);
    }

    public function test_organizer_can_approve_refund_case_and_create_adjustment(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );

        $organizer = User::factory()->create();
        $organizer->assignRole('artist', $organizer->id);
        $buyer = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 40000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 100,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) Str::uuid(),
            'confirmation_code' => 'CASE-2001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Case Buyer',
            'attendee_email' => 'case-buyer@example.com',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
            'price_paid_ugx' => 40000,
            'amount_paid' => 42000,
            'payment_reference' => 'PAY-CASE-1',
            'attendee_metadata' => [
                'order_id' => 'ORD-CASE-2',
                'fee_breakdown' => [
                    'base_amount' => 40000,
                    'total_amount' => 42000,
                    'total_fee_amount' => 2000,
                    'platform_commission_amount' => 1500,
                    'processing_fee_amount' => 500,
                    'organizer_net_amount' => 38000,
                ],
                'line_item_fee_breakdown' => [
                    'base_amount' => 40000,
                    'discounted_base_amount' => 40000,
                    'total_amount' => 42000,
                    'total_fee_amount' => 2000,
                    'platform_commission_amount' => 1500,
                    'processing_fee_amount' => 500,
                    'organizer_net_amount' => 38000,
                ],
            ],
        ]);

        $this->actingAs($buyer)->postJson("/api/tickets/{$attendee->id}/cases", [
            'case_type' => 'refund_request',
            'reason' => 'Unable to attend anymore because of travel changes.',
            'requested_refund_amount' => 42000,
        ])->assertCreated();

        $caseId = EventTicketCase::query()->value('id');

        $response = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/ticket-cases/{$caseId}/resolve", [
            'decision' => 'approve',
            'resolution_notes' => 'Approved by organizer support desk.',
            'approved_refund_amount' => 42000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $attendee->refresh();
        $ticket->refresh();

        $this->assertSame(EventAttendee::STATUS_CANCELLED, $attendee->status);
        $this->assertSame(0, $ticket->quantity_sold);
        $this->assertDatabaseHas('event_payout_ledger_entries', [
            'event_id' => $event->id,
            'fee_source' => 'support_case_adjustment',
            'payout_status' => EventPayoutLedgerEntry::STATUS_FAILED,
        ]);

        $analytics = $this->actingAs($organizer)->getJson("/api/artist/events/{$event->id}/analytics");
        $analytics->assertOk()
            ->assertJsonPath('data.support_cases.approved', 1)
            ->assertJsonPath('data.support_cases.approved_refund_amount', 42000);
    }

    public function test_finance_staff_can_view_event_ticket_case_queue(): void
    {
        $staffUser = User::factory()->create();
        $organizer = User::factory()->create();
        $buyer = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Standard',
            'price_ugx' => 15000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 30,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        EventStaffMember::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $staffUser->id,
            'invited_by_user_id' => $organizer->id,
            'role' => EventStaffMember::ROLE_FINANCE,
            'is_active' => true,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) Str::uuid(),
            'confirmation_code' => 'CASE-3001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Finance Queue Buyer',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
        ]);

        $this->actingAs($buyer)->postJson("/api/tickets/{$attendee->id}/cases", [
            'case_type' => 'payment_dispute',
            'reason' => 'Payment receipt is missing from my side after checkout.',
            'dispute_category' => 'payment_not_confirmed',
            'gateway_reference' => 'MM-REF-CASE-3001',
            'evidence_url' => 'https://example.com/evidence/case-3001',
            'evidence_notes' => 'Buyer shared mobile money screenshot for reconciliation.',
        ])->assertCreated();

        $response = $this->actingAs($staffUser)->getJson("/api/artist/events/{$event->id}/ticket-cases");

        $response->assertOk()
            ->assertJsonPath('data.0.case_type', 'payment_dispute')
            ->assertJsonPath('data.0.dispute_category', 'payment_not_confirmed')
            ->assertJsonPath('data.0.gateway_reference', 'MM-REF-CASE-3001')
            ->assertJsonPath('data.0.escalation_status', 'review')
            ->assertJsonPath('data.0.attendee.ticket_number', 'CASE-3001');
    }

    public function test_payment_dispute_updates_chargeback_exposure_analytics(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );

        $organizer = User::factory()->create();
        $organizer->assignRole('artist', $organizer->id);
        $buyer = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Standard',
            'price_ugx' => 30000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 20,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) Str::uuid(),
            'confirmation_code' => 'CASE-4001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Dispute Buyer',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
            'amount_paid' => 30000,
            'price_paid_ugx' => 30000,
        ]);

        $this->actingAs($buyer)->postJson("/api/tickets/{$attendee->id}/cases", [
            'case_type' => 'payment_dispute',
            'dispute_category' => 'charged_twice',
            'reason' => 'I was charged twice during the checkout confirmation step.',
            'requested_refund_amount' => 30000,
        ])->assertCreated();

        $analytics = $this->actingAs($organizer)->getJson("/api/artist/events/{$event->id}/analytics");
        $analytics->assertOk()
            ->assertJsonPath('data.support_cases.payment_disputes', 1)
            ->assertJsonPath('data.support_cases.open_payment_disputes', 1)
            ->assertJsonPath('data.support_cases.chargeback_review_cases', 1)
            ->assertJsonPath('data.support_cases.chargeback_exposure_amount', 30000);
    }
}
