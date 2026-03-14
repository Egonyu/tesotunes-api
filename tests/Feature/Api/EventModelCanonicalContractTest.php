<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventModelCanonicalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_register_uses_existing_attendee_schema_fields(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create([
            'is_free' => true,
            'registration_deadline' => now()->addDay(),
        ]);

        $attendee = $event->register($user, [
            'attendee_name' => 'Canonical Guest',
            'attendee_phone' => '0700000009',
        ]);

        $this->assertDatabaseHas('event_attendees', [
            'id' => $attendee->id,
            'event_id' => $event->id,
            'user_id' => $user->id,
            'attendee_name' => 'Canonical Guest',
            'attendee_email' => $user->email,
            'attendee_phone' => '0700000009',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_method' => EventAttendee::PAYMENT_METHOD_FREE,
            'payment_status' => 'completed',
        ]);
    }

    public function test_attended_status_counts_as_confirmed_and_checked_in_for_event_stats(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'EVT-ATTEND-001',
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventAttendee::STATUS_ATTENDED,
            'checked_in_at' => now(),
            'attended_at' => now(),
            'payment_status' => 'completed',
        ]);

        $event->refresh();

        $this->assertSame(1, $event->confirmed_attendees_count);
        $this->assertSame(1, $event->checked_in_attendees_count);
        $this->assertTrue($event->isAttendedBy($user));
    }
}
