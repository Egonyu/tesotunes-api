<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTicketContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_validate_owned_ticket_number(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General',
            'price_ugx' => 10000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-ABC123',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'attendee_name' => 'Test User',
            'attendee_email' => $user->email,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($user)->getJson('/api/tickets/validate/'.$attendee->confirmation_code);

        $response->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.ticket_number', 'TKT-ABC123')
            ->assertJsonPath('data.event.id', $event->id);
    }

    public function test_unrelated_user_cannot_validate_someone_elses_ticket(): void
    {
        $holder = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->published()->create();

        $attendee = EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-PRIVATE',
            'event_id' => $event->id,
            'user_id' => $holder->id,
            'attendee_name' => 'Holder Name',
            'attendee_email' => $holder->email,
            'status' => 'confirmed',
        ]);

        $this->actingAs($stranger)
            ->getJson('/api/tickets/validate/'.$attendee->confirmation_code)
            ->assertForbidden()
            ->assertJsonPath('valid', false);
    }

    public function test_unrelated_user_cannot_check_in_a_ticket(): void
    {
        $holder = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->published()->create();

        $attendee = EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-DOOR-1',
            'event_id' => $event->id,
            'user_id' => $holder->id,
            'attendee_name' => 'Holder Name',
            'attendee_email' => $holder->email,
            'status' => 'confirmed',
        ]);

        $this->actingAs($stranger)
            ->postJson('/api/tickets/check-in', ['ticket_number' => 'TKT-DOOR-1'])
            ->assertForbidden();

        $this->assertNull($attendee->fresh()->checked_in_at);
    }

    public function test_ticket_holder_cannot_check_in_their_own_ticket(): void
    {
        $holder = User::factory()->create();
        $event = Event::factory()->published()->create();

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-SELF-1',
            'event_id' => $event->id,
            'user_id' => $holder->id,
            'attendee_name' => 'Holder Name',
            'attendee_email' => $holder->email,
            'status' => 'confirmed',
        ]);

        $this->actingAs($holder)
            ->postJson('/api/tickets/check-in', ['ticket_number' => 'TKT-SELF-1'])
            ->assertForbidden();
    }

    public function test_event_owner_can_check_in_a_ticket(): void
    {
        $organizer = User::factory()->create();
        $holder = User::factory()->create();
        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-OWNER-1',
            'event_id' => $event->id,
            'user_id' => $holder->id,
            'attendee_name' => 'Holder Name',
            'attendee_email' => $holder->email,
            'status' => 'confirmed',
        ]);

        $this->actingAs($organizer)
            ->postJson('/api/tickets/check-in', ['ticket_number' => 'TKT-OWNER-1'])
            ->assertOk();

        $this->assertNotNull($attendee->fresh()->checked_in_at);
    }

    public function test_check_in_staff_member_can_check_in_a_ticket(): void
    {
        $organizer = User::factory()->create();
        $staffUser = User::factory()->create();
        $holder = User::factory()->create();
        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        \App\Models\EventStaffMember::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $staffUser->id,
            'invited_by_user_id' => $organizer->id,
            'role' => \App\Models\EventStaffMember::ROLE_CHECK_IN,
            'is_active' => true,
        ]);

        $attendee = EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'TKT-STAFF-9',
            'event_id' => $event->id,
            'user_id' => $holder->id,
            'attendee_name' => 'Holder Name',
            'attendee_email' => $holder->email,
            'status' => 'confirmed',
        ]);

        $this->actingAs($staffUser)
            ->postJson('/api/tickets/check-in', ['ticket_number' => 'TKT-STAFF-9'])
            ->assertOk();

        $this->assertNotNull($attendee->fresh()->checked_in_at);
    }

    public function test_wallet_purchase_uses_event_tickets_table_contract(): void
    {
        $user = User::factory()->create([
            'ugx_balance' => 100000,
            'credits' => 0,
        ]);

        $event = Event::factory()->published()->create();
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

        $response = $this->actingAs($user)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
            'payment_method' => 'wallet',
            'holder_name' => 'Wallet Buyer',
            'holder_email' => $user->email,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method', 'wallet');

        $this->assertDatabaseCount('event_attendees', 2);
        $this->assertDatabaseHas('event_tickets', [
            'id' => $ticket->id,
            'quantity_sold' => 2,
            'quantity_reserved' => 0,
        ]);
        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'payment_method' => 'wallet',
            'payment_status' => 'completed',
        ]);
    }

    public function test_credit_purchase_completes_and_deducts_credit_wallet_balance(): void
    {
        $user = User::factory()->create();
        $user->ensureCreditWallet()->update(['balance' => 300]);

        $event = Event::factory()->published()->create();
        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Credits Pass',
            'price_ugx' => 0,
            'price_credits' => 120,
            'quantity_total' => 10,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/tickets/purchase', [
            'event_id' => $event->id,
            'ticket_tier_id' => $ticket->id,
            'quantity' => 2,
            'payment_method' => 'credits',
            'holder_name' => 'Credits Buyer',
            'holder_email' => $user->email,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method', 'credits');

        $this->assertSame(60, $user->fresh()->credits);
        $this->assertDatabaseHas('event_attendees', [
            'ticket_id' => $ticket->id,
            'payment_method' => 'credits',
            'payment_status' => 'completed',
            'price_paid_credits' => 120,
        ]);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'source' => 'event_ticket_purchase',
            'amount' => 240,
        ]);
    }
}
