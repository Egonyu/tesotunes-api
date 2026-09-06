<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An admin setting an event up on an artist's behalf.
 *
 * The admin create endpoint used to hard-wire organizer_id, user_id and
 * artist_id to the authenticated admin, with no way to name the artist. Since
 * EventTicketingService settles ticket proceeds to
 * `organizer ?? user ?? artist->user`, every ticket sold on an admin-created
 * event paid the admin rather than the artist it was set up for.
 */
class AdminEventArtistAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Admin', 'description' => 'Administrator', 'is_active' => true, 'priority' => 5]
        );

        $admin = User::factory()->create();
        $admin->assignRole('admin', $admin->id);

        return $admin;
    }

    public function test_admin_can_set_up_an_event_for_an_artist(): void
    {
        $admin = $this->admin();
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);

        $response = $this->actingAs($admin)->postJson('/api/admin/events', [
            'title' => 'Teete Experience',
            'artist_id' => $artist->id,
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ]);

        $response->assertSuccessful();

        $event = Event::query()->where('title', 'Teete Experience')->firstOrFail();

        $this->assertSame($artist->id, $event->artist_id, 'the event must be attributed to the artist');
        $this->assertSame(
            $artistUser->id,
            $event->organizer_id,
            'ticket proceeds settle to the organizer, so it must be the artist and not the admin'
        );
        $this->assertSame($artistUser->id, $event->user_id);
    }

    public function test_the_settlement_beneficiary_is_the_artist_not_the_admin(): void
    {
        $admin = $this->admin();
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);

        $this->actingAs($admin)->postJson('/api/admin/events', [
            'title' => 'Beneficiary check',
            'artist_id' => $artist->id,
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertSuccessful();

        $event = Event::query()->where('title', 'Beneficiary check')->firstOrFail();

        // Mirrors EventTicketingService::recordOrganizerProceeds().
        $beneficiary = $event->organizer ?? $event->user ?? $event->artist?->user;

        $this->assertNotNull($beneficiary);
        $this->assertSame($artistUser->id, $beneficiary->id);
        $this->assertNotSame($admin->id, $beneficiary->id);
    }

    public function test_an_event_created_without_an_artist_still_belongs_to_the_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/events', [
            'title' => 'House event',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertSuccessful();

        $event = Event::query()->where('title', 'House event')->firstOrFail();

        $this->assertSame($admin->id, $event->organizer_id);
        $this->assertSame($admin->id, $event->user_id);
    }

    public function test_admin_can_reassign_an_existing_event_to_an_artist(): void
    {
        $admin = $this->admin();
        $artistUser = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $artistUser->id]);

        $event = Event::factory()->create([
            'organizer_id' => $admin->id,
            'user_id' => $admin->id,
            'artist_id' => null,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/events/{$event->id}", ['artist_id' => $artist->id])
            ->assertSuccessful();

        $event->refresh();

        $this->assertSame($artist->id, $event->artist_id);
        $this->assertSame($artistUser->id, $event->organizer_id);
    }

    public function test_an_unknown_artist_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/events', [
            'title' => 'Ghost artist',
            'artist_id' => 999999,
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('artist_id');
    }

    public function test_poster_and_banner_are_stored_as_separate_images(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/api/admin/events', [
            'title' => 'Two images',
            'starts_at' => now()->addWeek()->toDateTimeString(),
            'cover_image' => UploadedFile::fake()->image('poster.jpg', 1080, 1350),
            'banner_image' => UploadedFile::fake()->image('banner.jpg', 1920, 1080),
        ])->assertSuccessful();

        $event = Event::query()->where('title', 'Two images')->firstOrFail();

        $this->assertNotNull($event->artwork, 'the portrait poster belongs in artwork');
        $this->assertNotNull($event->banner, 'the wide banner belongs in banner');
        $this->assertNotSame($event->artwork, $event->banner);
    }
}
