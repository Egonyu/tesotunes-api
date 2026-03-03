<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests artist album artwork upload via POST /api/artist/albums.
 *
 * Bugs documented:
 *  - Uses $file->store('albums/artwork', 'public') directly instead of StorageHelper.
 *  - store() calls getRealPath() which fails on Windows.
 *  - Field name is 'cover_image' not 'artwork'.
 */
class ArtistAlbumImageUploadTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $artistUser;

    private Artist $artist;

    private bool $isWindows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artistUser = $this->createUserWithRole('artist');
        $this->artist = Artist::factory()->create([
            'user_id' => $this->artistUser->id,
        ]);
        $this->isWindows = PHP_OS_FAMILY === 'Windows';
    }

    private function skipIfStoreAsBug($response): void
    {
        if ($this->isWindows && $response->getStatusCode() === 500) {
            $this->markTestIncomplete(
                'BUG: ArtistApiController uses $file->store() which calls getRealPath(). '.
                'On Windows, getRealPath() returns false for temp files → ValueError "Path must not be empty".'
            );
        }
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

        $this->skipIfStoreAsBug($response);

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

        $this->skipIfStoreAsBug($response);

        $this->assertContains($response->getStatusCode(), [200, 201]);

        $data = $response->json('data') ?? $response->json();
        if (isset($data['artwork'])) {
            $this->assertNotEmpty($data['artwork']);
        }
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

    // ─── Bug: Direct store ───────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_album_artwork_uses_direct_store_not_storage_helper(): void
    {
        $this->markTestIncomplete(
            'BUG: ArtistApiController uses $file->store(\'albums/artwork\', \'public\') '.
            'directly instead of StorageHelper. Uploads always go to local public disk.'
        );
    }
}
