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
}
