<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEventActionsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_publish_and_feature_an_event(): void
    {
        Role::factory()->admin()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $event = Event::factory()->create([
            'status' => 'draft',
            'is_published' => false,
            'is_featured' => false,
        ]);

        $publish = $this->actingAs($admin)->postJson("/api/admin/events/{$event->id}/publish");
        $publish->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_published', true);

        $feature = $this->actingAs($admin)->postJson("/api/admin/events/{$event->id}/toggle-featured");
        $feature->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_featured', true);
    }

    public function test_admin_can_view_event_attendees_and_analytics(): void
    {
        Role::factory()->admin()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        $buyer = User::factory()->create();
        $interestedUser = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $admin->id,
            'attendee_count' => 1,
        ]);

        $ticket = EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'VIP',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'quantity_total' => 100,
            'quantity_sold' => 1,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'EVT-ADMIN-001',
            'event_id' => $event->id,
            'ticket_id' => $ticket->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Buyer One',
            'attendee_email' => $buyer->email,
            'attendee_phone' => '0700000001',
            'status' => 'confirmed',
            'payment_status' => 'completed',
            'quantity' => 1,
            'amount_paid' => 25000,
            'price_paid_ugx' => 25000,
            'checked_in_at' => now(),
            'confirmed_at' => now(),
        ]);

        $interestedUser->interestedEvents()->attach($event->id);

        $attendees = $this->actingAs($admin)->getJson("/api/admin/events/{$event->id}/attendees");
        $attendees->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ticket_number', 'EVT-ADMIN-001')
            ->assertJsonPath('data.0.ticket.name', 'VIP');

        $analytics = $this->actingAs($admin)->getJson("/api/admin/events/{$event->id}/analytics");
        $analytics->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tickets_sold', 1)
            ->assertJsonPath('data.interested_count', 1)
            ->assertJsonPath('data.check_ins', 1)
            ->assertJsonPath('data.by_tier.0.name', 'VIP');
    }
}
