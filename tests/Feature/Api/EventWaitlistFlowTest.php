<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventWaitlistEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventWaitlistFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_join_waitlist_for_sold_out_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();

        EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'quantity_total' => 10,
            'quantity_sold' => 10,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/events/{$event->id}/waitlist", [
            'email' => 'waitlist@example.com',
            'phone' => '0700111222',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.waitlist_joined', true)
            ->assertJsonPath('data.waitlist_count', 1);

        $this->assertDatabaseHas('event_waitlist_entries', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => 'waitlist@example.com',
            'status' => EventWaitlistEntry::STATUS_ACTIVE,
        ]);
    }

    public function test_waitlist_requires_event_to_be_sold_out(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();

        EventTicket::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price_ugx' => 25000,
            'price_credits' => 0,
            'quantity_total' => 10,
            'quantity_sold' => 2,
            'quantity_reserved' => 0,
            'max_per_order' => 4,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/api/events/{$event->id}/waitlist");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Tickets are still available for this event.');
    }
}
