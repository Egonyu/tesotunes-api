<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventTicket;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicArtistEventsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_artist_events_endpoint_returns_upcoming_and_past_events(): void
    {
        $viewer = User::factory()->create();
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->verified()->create([
            'user_id' => $artistUser->id,
            'status' => 'active',
            'stage_name' => 'Test Artist',
            'slug' => 'test-artist',
        ]);

        $upcoming = Event::factory()->published()->create([
            'organizer_id' => $artistUser->id,
            'user_id' => $artistUser->id,
            'artist_id' => $artist->id,
            'title' => 'Upcoming Show',
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHours(3),
            'venue_name' => 'Serena Hall',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'attendee_count' => 1,
            'event_type' => 'concert',
        ]);

        $past = Event::factory()->published()->create([
            'organizer_id' => $artistUser->id,
            'user_id' => $artistUser->id,
            'artist_id' => $artist->id,
            'title' => 'Past Show',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(10)->addHours(2),
            'venue_name' => 'City Stage',
            'city' => 'Jinja',
            'country' => 'Uganda',
            'event_type' => 'festival',
        ]);

        foreach ([$upcoming, $past] as $index => $event) {
            EventTicket::create([
                'uuid' => (string) \Str::uuid(),
                'event_id' => $event->id,
                'name' => 'General',
                'price_ugx' => 15000 + ($index * 5000),
                'price_credits' => 0,
                'quantity_total' => 100,
                'quantity_sold' => 1,
                'quantity_reserved' => 0,
                'max_per_order' => 4,
                'is_active' => true,
            ]);
        }

        EventAttendee::create([
            'uuid' => (string) \Str::uuid(),
            'confirmation_code' => 'ART-EVT-001',
            'event_id' => $upcoming->id,
            'ticket_id' => $upcoming->tickets()->first()->id,
            'user_id' => $viewer->id,
            'attendee_name' => 'Viewer',
            'attendee_email' => $viewer->email,
            'status' => 'confirmed',
        ]);

        $viewer->interestedEvents()->attach($upcoming->id);
        UserFollow::create([
            'follower_id' => $viewer->id,
            'following_id' => $artistUser->id,
        ]);

        $response = $this->actingAs($viewer)->getJson("/api/artists/{$artist->slug}/events");

        $response->assertOk()
            ->assertJsonPath('artist.slug', 'test-artist')
            ->assertJsonPath('upcoming.0.title', 'Upcoming Show')
            ->assertJsonPath('upcoming.0.is_attending', true)
            ->assertJsonPath('upcoming.0.is_interested', true)
            ->assertJsonPath('past.0.title', 'Past Show')
            ->assertJsonPath('past.0.status', 'past');
    }
}
