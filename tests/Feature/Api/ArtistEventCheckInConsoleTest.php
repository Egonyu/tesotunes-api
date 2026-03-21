<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventStaffMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistEventCheckInConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_lookup_and_check_in_attendee_with_duplicate_override(): void
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
        $buyer = User::factory()->create();

        $attendee = EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'CHK-0001',
            'event_id' => $event->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Door Guest',
            'attendee_email' => 'door@example.com',
            'attendee_phone' => '0700000008',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
        ]);

        $lookup = $this->actingAs($organizer)->getJson("/api/artist/events/{$event->id}/check-in/lookup?query=CHK-0001");
        $lookup->assertOk()
            ->assertJsonPath('data.matches.0.ticket_number', 'CHK-0001')
            ->assertJsonPath('data.matches.0.duplicate_warning', false);

        $checkIn = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/check-in", [
            'ticket_number' => 'CHK-0001',
            'notes' => 'Main gate entry',
        ]);

        $checkIn->assertOk()
            ->assertJsonPath('data.ticket_number', 'CHK-0001')
            ->assertJsonPath('data.duplicate_warning', false);

        $duplicate = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/check-in", [
            'ticket_number' => 'CHK-0001',
        ]);

        $duplicate->assertStatus(422)
            ->assertJsonPath('data.duplicate_warning', true);

        $override = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/check-in", [
            'ticket_number' => 'CHK-0001',
            'notes' => 'Supervisor override',
            'allow_duplicate' => true,
        ]);

        $override->assertOk()
            ->assertJsonPath('data.ticket_number', 'CHK-0001');

        $attendee->refresh();

        $this->assertSame(EventAttendee::STATUS_ATTENDED, $attendee->status);
        $this->assertNotNull($attendee->checked_in_at);
        $this->assertSame('Supervisor override', $attendee->notes);
        $this->assertTrue((bool) data_get($attendee->attendee_metadata, 'last_check_in_override'));
    }

    public function test_check_in_staff_member_can_access_event_ops_lookup(): void
    {
        $staffUser = User::factory()->create();
        $organizer = User::factory()->create();

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
        $buyer = User::factory()->create();

        EventStaffMember::create([
            'uuid' => (string) \Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $staffUser->id,
            'invited_by_user_id' => $organizer->id,
            'role' => EventStaffMember::ROLE_CHECK_IN,
            'is_active' => true,
        ]);

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'CHK-STAFF-1',
            'event_id' => $event->id,
            'user_id' => $buyer->id,
            'attendee_name' => 'Staff Guest',
            'attendee_email' => 'staff-guest@example.com',
            'status' => EventAttendee::STATUS_CONFIRMED,
            'payment_status' => 'completed',
        ]);

        $lookup = $this->actingAs($staffUser)->getJson("/api/artist/events/{$event->id}/check-in/lookup?query=STAFF");

        $lookup->assertOk()
            ->assertJsonPath('data.matches.0.ticket_number', 'CHK-STAFF-1');
    }
}
