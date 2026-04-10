<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistEventOrganizerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'user'],
            ['display_name' => 'User', 'description' => 'Standard user', 'is_active' => true, 'priority' => 1]
        );
    }

    public function test_event_organizer_without_artist_role_can_list_owned_events(): void
    {
        $organizer = User::factory()->create();
        $organizer->assignRole('user', $organizer->id);
        $organizer->syncEventOrganizerProfile([
            'enabled' => true,
            'business_name' => 'Organizer Profile',
        ]);

        Event::factory()->published()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);

        $response = $this->actingAs($organizer)->getJson('/api/artist/events');

        $response->assertOk()
            ->assertJsonPath('data.0.organizer.id', $organizer->id);
    }

    public function test_event_organizer_without_artist_role_can_create_event(): void
    {
        $organizer = User::factory()->create();
        $organizer->assignRole('user', $organizer->id);
        $organizer->syncEventOrganizerProfile([
            'enabled' => true,
            'business_name' => 'Organizer Profile',
        ]);

        $response = $this->actingAs($organizer)->postJson('/api/artist/events', [
            'title' => 'Organizer Owned Event',
            'status' => 'draft',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'city' => 'Kampala',
            'venue_name' => 'Freedom Grounds',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.organizer.id', $organizer->id)
            ->assertJsonPath('data.title', 'Organizer Owned Event');

        $this->assertDatabaseHas('events', [
            'title' => 'Organizer Owned Event',
            'organizer_id' => $organizer->id,
            'user_id' => $organizer->id,
        ]);
    }
}
