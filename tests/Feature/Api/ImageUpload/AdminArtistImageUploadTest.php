<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests admin artist image upload via POST /api/admin/artists/{id}.
 *
 * Uses StorageHelper (correct pattern). Fields: profile_image → avatar,
 * cover_image → cover_image. Uses POST not PUT.
 */
class AdminArtistImageUploadTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $admin;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('admin');
        $this->artist = Artist::factory()->create([
            'user_id' => $this->createUserWithRole('artist')->id,
        ]);
    }

    // ─── Profile Image (avatar) Upload ───────────────────────────

    public function test_admin_can_upload_artist_profile_image(): void
    {
        $file = UploadedFile::fake()->image('artist-avatar.jpg', 100, 100)->size(1024);

        $response = $this->actingAs($this->admin)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'profile_image' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->artist->refresh();
        $this->assertNotNull($this->artist->avatar, 'Avatar should be stored after upload');
        $this->assertStringContainsString('artists/avatars/', $this->artist->avatar);
    }

    public function test_admin_upload_replaces_old_avatar(): void
    {
        $this->artist->update(['avatar' => 'artists/avatars/old.jpg']);

        $file = UploadedFile::fake()->image('new.jpg', 100, 100);
        $response = $this->actingAs($this->admin)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'profile_image' => $file,
            ]);

        $response->assertOk();
        $this->artist->refresh();
        $this->assertNotNull($this->artist->avatar);
        $this->assertNotEquals('artists/avatars/old.jpg', $this->artist->avatar);
    }

    // ─── Cover Image Upload ──────────────────────────────────────

    public function test_admin_can_upload_artist_cover_image(): void
    {
        $file = UploadedFile::fake()->image('artist-cover.jpg', 200, 100)->size(2048);

        $response = $this->actingAs($this->admin)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'cover_image' => $file,
            ]);

        $response->assertOk();
        $this->artist->refresh();
        $this->assertNotNull($this->artist->cover_image, 'Cover image should be stored');
        $this->assertStringContainsString('artists/covers/', $this->artist->cover_image);
    }

    public function test_admin_can_upload_webp_images(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 100, 100);

        $response = $this->actingAs($this->admin)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'profile_image' => $file,
            ]);

        $response->assertOk();
    }

    // ─── Combined upload ─────────────────────────────────────────

    public function test_admin_can_upload_both_images_with_data(): void
    {
        $avatar = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        $cover = UploadedFile::fake()->image('cover.jpg', 200, 100);

        $response = $this->actingAs($this->admin)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'name' => 'Updated Stage Name',
                'bio' => 'Updated bio for admin test',
                'profile_image' => $avatar,
                'cover_image' => $cover,
            ]);

        $response->assertOk();
        $this->artist->refresh();
        $this->assertEquals('Updated Stage Name', $this->artist->stage_name);
        $this->assertNotNull($this->artist->avatar);
        $this->assertNotNull($this->artist->cover_image);
    }

    // ─── Validation ──────────────────────────────────────────────

    public function test_admin_artist_upload_validates_max_size(): void
    {
        $file = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(6000);

        $response = $this->actingAs($this->admin)
            ->post(
                "/api/admin/artists/{$this->artist->id}",
                ['profile_image' => $file],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    public function test_admin_artist_upload_rejects_non_image(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->post(
                "/api/admin/artists/{$this->artist->id}",
                ['profile_image' => $file],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    // ─── Authorization ───────────────────────────────────────────

    public function test_admin_artist_upload_requires_auth(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->post("/api/admin/artists/{$this->artist->id}", [
            'profile_image' => $file,
        ]);

        $response->assertUnauthorized();
    }

    public function test_admin_artist_upload_requires_admin_role(): void
    {
        $normalUser = User::factory()->create(['is_active' => true]);
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->actingAs($normalUser)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'profile_image' => $file,
            ]);

        $response->assertStatus(403);
    }

    // ─── Data-only update preserves images ───────────────────────

    public function test_admin_data_update_preserves_existing_images(): void
    {
        $this->artist->forceFill([
            'avatar' => 'artists/avatars/existing.jpg',
            'cover_image' => 'artists/covers/existing.jpg',
        ])->save();

        $response = $this->actingAs($this->admin)
            ->post("/api/admin/artists/{$this->artist->id}", [
                'bio' => 'Just updating the bio',
            ]);

        $response->assertOk();
        $this->artist->refresh();
        $this->assertEquals('artists/avatars/existing.jpg', $this->artist->avatar);
        $this->assertEquals('artists/covers/existing.jpg', $this->artist->cover_image);
    }
}
