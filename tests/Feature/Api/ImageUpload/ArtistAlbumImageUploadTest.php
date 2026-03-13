<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\Album;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests artist album artwork upload via POST /api/artist/albums.
 *
 * Verifies artist album artwork uploads persist and return cloud-safe paths.
 */
class ArtistAlbumImageUploadTest extends TestCase
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

    // ─── Create Album with Artwork ───────────────────────────────

    public function test_artist_can_create_album_with_artwork(): void
    {
        $artwork = UploadedFile::fake()->image('album-cover.jpg', 100, 100)->size(1024);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/albums', [
                'title' => 'My Test Album',
                'cover_image' => $artwork,
                'release_date' => now()->addDays(30)->format('Y-m-d'),
            ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);
    }

    public function test_artist_album_creation_stores_artwork_path(): void
    {
        $artwork = UploadedFile::fake()->image('art.jpg', 100, 100)->size(512);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/albums', [
                'title' => 'Album Art Test',
                'cover_image' => $artwork,
                'release_date' => now()->addDays(7)->format('Y-m-d'),
            ]);

        $this->assertContains($response->getStatusCode(), [200, 201]);

        $album = Album::latest('id')->first();
        $this->assertNotNull($album);
        $this->assertNotEmpty($album->artwork);
        $this->assertStringContainsString('albums/artwork/', $album->artwork);
    }

    // ─── Validation ──────────────────────────────────────────────

    public function test_album_artwork_rejects_non_image(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->artistUser)
            ->post(
                '/api/artist/albums',
                [
                    'title' => 'Album Bad Art',
                    'cover_image' => $file,
                ],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    // ─── Authorization ───────────────────────────────────────────

    public function test_album_upload_requires_auth(): void
    {
        $artwork = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->post('/api/artist/albums', [
            'title' => 'Unauth Album',
            'cover_image' => $artwork,
        ]);

        $response->assertUnauthorized();
    }

    public function test_album_upload_requires_artist_role(): void
    {
        $normalUser = User::factory()->create(['is_active' => true]);
        $artwork = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->actingAs($normalUser)
            ->post('/api/artist/albums', [
                'title' => 'No Artist Role',
                'cover_image' => $artwork,
            ]);

        $response->assertStatus(403);
    }

    public function test_album_artwork_uses_expected_storage_directory(): void
    {
        $artwork = UploadedFile::fake()->image('album-check.jpg', 100, 100);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/albums', [
                'title' => 'Album Storage Check',
                'cover_image' => $artwork,
                'release_date' => now()->addDays(10)->format('Y-m-d'),
            ]);

        $response->assertCreated();
        $album = Album::latest('id')->first();
        $this->assertNotNull($album);
        $this->assertStringContainsString('albums/artwork/', $album->artwork);
    }
}
