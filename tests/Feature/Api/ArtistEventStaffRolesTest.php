<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistEventStaffRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_can_add_and_remove_event_staff_members(): void
    {
        Role::factory()->artist()->create();

        $organizer = User::factory()->create();
        $organizer->assignRole('artist', $organizer->id);

        $financeUser = User::factory()->create([
            'email' => 'finance@example.com',
        ]);

        $event = Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $add = $this->actingAs($organizer)->postJson("/api/artist/events/{$event->id}/staff", [
            'user_email' => $financeUser->email,
            'role' => 'finance',
            'notes' => 'Handles settlement review',
        ]);

        $add->assertCreated()
            ->assertJsonPath('data.staff_members.0.role', 'organizer')
            ->assertJsonPath('data.staff_members.1.role', 'finance')
            ->assertJsonPath('data.staff_members.1.user.email', 'finance@example.com');

        $this->assertDatabaseHas('event_staff_members', [
            'event_id' => $event->id,
            'user_id' => $financeUser->id,
            'role' => 'finance',
            'is_active' => true,
        ]);

        $staffId = $event->fresh()->staffMembers()->firstOrFail()->id;

        $remove = $this->actingAs($organizer)->deleteJson("/api/artist/events/{$event->id}/staff/{$staffId}");

        $remove->assertOk()
            ->assertJsonCount(1, 'data.staff_members')
            ->assertJsonPath('data.staff_members.0.role', 'organizer');

        $this->assertDatabaseMissing('event_staff_members', [
            'id' => $staffId,
        ]);
    }
}
