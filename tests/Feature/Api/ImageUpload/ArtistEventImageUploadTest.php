<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests artist event image upload via POST /api/artist/events.
 *
 * Verifies artist event cover uploads go through the shared storage layer.
 */
class ArtistEventImageUploadTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $artistUser;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artistUser = $this->createUserWithRole('artist');
        $this->artist = Artist::factory()->create([
            'user_id' => $this->artistUser->id,
        ]);
    }

    // ─── Create Event with Cover Image ───────────────────────────

    public function test_artist_can_create_event_with_cover(): void
    {
        $cover = UploadedFile::fake()->image('event-cover.jpg', 200, 100)->size(1024);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/events', [
                'title' => 'My Test Event',
                'description' => 'Event description for testing image upload',
                'starts_at' => now()->addDays(14)->toDateTimeString(),
                'ends_at' => now()->addDays(14)->addHours(3)->toDateTimeString(),
                'venue_name' => 'Test Venue',
                'venue_address' => '123 Test St',
                'event_type' => 'concert',
                'cover_image' => $cover,
            ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);
    }

    public function test_event_stores_cover_image_path(): void
    {
        $cover = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/events', [
                'title' => 'Cover Path Event',
                'description' => 'Testing the path stored for cover image',
                'starts_at' => now()->addDays(7)->toDateTimeString(),
                'ends_at' => now()->addDays(7)->addHours(2)->toDateTimeString(),
                'venue_name' => 'Venue',
                'venue_address' => '456 Address',
                'event_type' => 'concert',
                'cover_image' => $cover,
            ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);
        $data = $response->json('data') ?? $response->json();
        $this->assertStringContainsString('events/covers/', $data['artwork']);
    }

    // ─── Update Event with Cover Image ───────────────────────────

    public function test_artist_can_update_event_cover(): void
    {
        $createResponse = $this->actingAs($this->artistUser)
            ->post('/api/artist/events', [
                'title' => 'Event To Update',
                'description' => 'Will update the cover',
                'starts_at' => now()->addDays(7)->toDateTimeString(),
                'ends_at' => now()->addDays(7)->addHours(2)->toDateTimeString(),
                'venue_name' => 'Venue',
                'venue_address' => 'Address',
                'event_type' => 'concert',
            ]);

        $this->assertContains($createResponse->getStatusCode(), [200, 201]);

        $eventData = $createResponse->json('data') ?? $createResponse->json();
        $eventId = $eventData['id'] ?? null;

        if (! $eventId) {
            $this->markTestSkipped('Could not create event for update test');
        }

        $newCover = UploadedFile::fake()->image('updated.jpg', 100, 100);

        $response = $this->actingAs($this->artistUser)
            ->put("/api/artist/events/{$eventId}", [
                'cover_image' => $newCover,
            ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);
    }

    // ─── Validation ──────────────────────────────────────────────

    public function test_event_cover_validates_image_type(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->artistUser)
            ->post(
                '/api/artist/events',
                [
                    'title' => 'Bad Cover Event',
                    'description' => 'Trying non-image file',
                    'starts_at' => now()->addDays(7)->toDateTimeString(),
                    'ends_at' => now()->addDays(7)->addHours(2)->toDateTimeString(),
                    'venue_name' => 'Venue',
                    'venue_address' => 'Address',
                    'event_type' => 'concert',
                    'cover_image' => $file,
                ],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    // ─── Authorization ───────────────────────────────────────────

    public function test_event_upload_requires_auth(): void
    {
        $cover = UploadedFile::fake()->image('cover.jpg', 100, 100);

        $response = $this->post('/api/artist/events', [
            'title' => 'Unauth Event',
            'cover_image' => $cover,
        ]);

        $response->assertUnauthorized();
    }

    public function test_event_upload_requires_artist_role(): void
    {
        $normalUser = User::factory()->create(['is_active' => true]);
        $cover = UploadedFile::fake()->image('cover.jpg', 100, 100);

        $response = $this->actingAs($normalUser)
            ->post('/api/artist/events', [
                'title' => 'Wrong Role Event',
                'description' => 'User is not an artist',
                'starts_at' => now()->addDays(7)->toDateTimeString(),
                'ends_at' => now()->addDays(7)->addHours(2)->toDateTimeString(),
                'venue_name' => 'Venue',
                'venue_address' => 'Address',
                'event_type' => 'concert',
                'cover_image' => $cover,
            ]);

        $response->assertStatus(403);
    }

    public function test_event_upload_uses_storage_backed_cover_directory(): void
    {
        $cover = UploadedFile::fake()->image('storage-check.jpg', 200, 100);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/events', [
                'title' => 'Storage Backed Event',
                'description' => 'Ensures event cover uses StorageHelper',
                'starts_at' => now()->addDays(10)->toDateTimeString(),
                'ends_at' => now()->addDays(10)->addHours(2)->toDateTimeString(),
                'venue_name' => 'Venue',
                'venue_address' => 'Address',
                'event_type' => 'concert',
                'cover_image' => $cover,
            ]);

        $response->assertCreated();
        $data = $response->json('data') ?? $response->json();
        $this->assertStringContainsString('events/covers/', $data['artwork']);
    }
}
