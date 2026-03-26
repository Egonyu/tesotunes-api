<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventStaffMember;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventOfflineSalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_log_offline_sale_and_capacity_updates(): void
    {
        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Verified artist', 'is_active' => true, 'priority' => 2]
        );

        $organizer = User::factory()->create();
        $organizer->assignRole('artist', $organizer->id);

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Gate Pass',
            'price_ugx' => 20000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 20,
            'quantity_sold' => 0,
            'quantity_reserved' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/offline-sales", [
            'ticket_tier_id' => $ticket->id,
            'quantity' => 3,
            'holder_name' => 'Door Buyer',
            'holder_phone' => '0701000001',
            'sale_source' => 'printed_ticket',
            'notes' => 'Sold from paper booklet',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.sale_source', 'printed_ticket');

        $ticket->refresh();

        $this->assertSame(3, $ticket->quantity_sold);
        $this->assertDatabaseCount('event_attendees', 3);

        $analytics = $this->actingAs($organizer)->getJson("/api/artist/events/{$event->id}/analytics");
        $analytics->assertOk()
            ->assertJsonPath('data.sales_channels.channels.0.key', 'manual_offline');
    }

    public function test_finance_staff_can_view_and_void_offline_sale_order(): void
    {
        $organizer = User::factory()->create();
        $staffUser = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) Str::uuid(),
            'event_id' => $event->id,
            'name' => 'Printed Tier',
            'price_ugx' => 10000,
            'price_credits' => 0,
            'is_free' => false,
            'quantity_total' => 10,
            'quantity_sold' => 2,
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

        $orderId = 'OFFLINE-VOID-1';
        $offlineBuyer = User::factory()->create();

        foreach (range(1, 2) as $index) {
            EventAttendee::create([
                'uuid' => (string) Str::uuid(),
                'confirmation_code' => 'OFL-VOID-'.$index,
                'event_id' => $event->id,
                'ticket_id' => $ticket->id,
                'user_id' => $offlineBuyer->id,
                'attendee_name' => 'Printed Buyer #'.$index,
                'status' => EventAttendee::STATUS_CONFIRMED,
                'payment_status' => 'completed',
                'payment_method' => 'manual_offline',
                'price_paid_ugx' => 10000,
                'amount_paid' => 10000,
                'attendee_metadata' => [
                    'order_id' => $orderId,
                    'sales_channel' => 'manual_offline',
                    'offline_sale' => true,
                    'offline_sale_source' => 'printed_ticket',
                ],
            ]);
        }

        $list = $this->actingAs($staffUser)->getJson("/api/artist/events/{$event->id}/offline-sales");
        $list->assertOk()
            ->assertJsonPath('data.0.order_id', $orderId)
            ->assertJsonPath('data.0.quantity', 2);

        $void = $this->actingAs($staffUser)->postJson("/api/artist/events/{$event->id}/offline-sales/{$orderId}/void", [
            'reason' => 'Booklet entry duplicated by mistake',
        ]);

        $void->assertOk();

        $ticket->refresh();
        $this->assertSame(0, $ticket->quantity_sold);
        $this->assertDatabaseHas('event_attendees', [
            'confirmation_code' => 'OFL-VOID-1',
            'status' => EventAttendee::STATUS_CANCELLED,
        ]);
    }
}
