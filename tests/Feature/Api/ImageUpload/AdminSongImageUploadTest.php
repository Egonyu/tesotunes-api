<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests admin song artwork upload via POST/PUT /api/admin/songs/{id}.
 */
class AdminSongImageUploadTest extends TestCase
{
    use CreatesUsersWithRoles, DatabaseTransactions;

    private User $admin;

    private Artist $artist;

    private Song $song;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('admin');
        $artistUser = $this->createUserWithRole('artist');
        $this->artist = Artist::factory()->create([
            'user_id' => $artistUser->id,
            'status' => 'active',
        ]);
        $this->song = Song::factory()->create([
            'user_id' => $artistUser->id,
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'artwork' => 'songs/artwork/existing-cover.jpg',
        ]);
    }

    // ─── Create Song with Artwork ────────────────────────────────

    public function test_admin_can_create_song_with_artwork(): void
    {
        $genre = Genre::first() ?? Genre::factory()->create();
        $artwork = UploadedFile::fake()->image('cover.jpg', 100, 100)->size(1024);
        $audio = UploadedFile::fake()->create('song.mp3', 2048, 'audio/mpeg');

        $response = $this->actingAs($this->admin)
            ->post('/api/admin/songs', [
                'title' => 'Test Song With Art',
                'artist_id' => $this->artist->id,
                'genre_ids' => [$genre->id],
                'cover_image' => $artwork,
                'audio_file' => $audio,
                'status' => 'published',
            ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertStringContainsString('songs/artwork/', $response->json('data.artwork_url'));
    }

    // ─── Update Song Artwork ─────────────────────────────────────

    public function test_admin_can_update_song_artwork(): void
    {
        $artwork = UploadedFile::fake()->image('new-art.jpg', 100, 100)->size(512);

        $response = $this->actingAs($this->admin)
            ->put("/api/admin/songs/{$this->song->id}", [
                'artwork' => $artwork,
            ]);

        $response->assertOk();
        $this->song->refresh();
        $this->assertStringContainsString('songs/artwork/', $this->song->artwork);
        $this->assertNotSame('songs/artwork/existing-cover.jpg', $this->song->artwork);
    }

    // ─── Validation ──────────────────────────────────────────────

    public function test_admin_song_artwork_validates_max_size(): void
    {
        $artwork = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(11000);

        $response = $this->actingAs($this->admin)
            ->put(
                "/api/admin/songs/{$this->song->id}",
                ['artwork' => $artwork],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    public function test_admin_song_artwork_rejects_non_image(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->put(
                "/api/admin/songs/{$this->song->id}",
                ['artwork' => $file],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    // ─── Authorization ───────────────────────────────────────────

    public function test_admin_song_upload_requires_auth(): void
    {
        $artwork = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->put("/api/admin/songs/{$this->song->id}", [
            'artwork' => $artwork,
        ]);

        $response->assertUnauthorized();
    }

    public function test_admin_song_upload_requires_admin_role(): void
    {
        $normalUser = User::factory()->create(['is_active' => true]);
        $artwork = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->actingAs($normalUser)
            ->put("/api/admin/songs/{$this->song->id}", [
                'artwork' => $artwork,
            ]);

        $response->assertStatus(403);
    }

    public function test_song_artwork_alias_is_accepted_for_updates(): void
    {
        $artwork = UploadedFile::fake()->image('alias-cover.jpg', 120, 120);

        $response = $this->actingAs($this->admin)
            ->put("/api/admin/songs/{$this->song->id}", [
                'artwork' => $artwork,
            ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->song->refresh();
        $this->assertStringContainsString('songs/artwork/', $this->song->artwork);
    }
}
