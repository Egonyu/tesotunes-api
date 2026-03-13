<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Helpers\StorageHelper;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests for user profile image upload via PUT /api/user.
 *
 * Verifies profile uploads use the shared storage helper and persist
 * banner images on the correct user column for cloud-compatible storage.
 */
class UserProfileImageUploadTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_active' => true]);
    }

    // ─── Avatar Upload ────────────────────────────────────────────

    public function test_profile_avatar_upload_succeeds(): void
    {
        $file = UploadedFile::fake()->image('my-avatar.jpg', 100, 100)->size(512);

        $response = $this->actingAs($this->user)
            ->put('/api/user', ['avatar' => $file]);

        $response->assertOk();
        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);
        $this->assertStringContainsString('avatars/', $this->user->avatar);
    }

    public function test_profile_avatar_deletes_old_file(): void
    {
        $this->user->update(['avatar' => 'avatars/old.jpg']);

        $file = UploadedFile::fake()->image('new.jpg', 50, 50);
        $response = $this->actingAs($this->user)->put('/api/user', ['avatar' => $file]);

        $response->assertOk();
        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);
        $this->assertNotEquals('avatars/old.jpg', $this->user->avatar);
    }

    public function test_profile_avatar_validates_max_size(): void
    {
        $file = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(3000);

        $response = $this->actingAs($this->user)
            ->put('/api/user', ['avatar' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_profile_avatar_validates_image_type(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->put('/api/user', ['avatar' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    // ─── Cover Image ─────────────────────────────────────────────

    public function test_cover_image_upload_updates_banner_column(): void
    {
        $file = UploadedFile::fake()->image('cover.webp', 300, 200);

        $response = $this->actingAs($this->user)
            ->put('/api/user', ['cover_image' => $file], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->user->refresh();

        $this->assertNotNull($this->user->banner);
        $this->assertStringContainsString('covers/', $this->user->banner);
        $this->assertEquals(StorageHelper::url($this->user->banner), $response->json('data.banner'));
    }

    // ─── Combined upload ─────────────────────────────────────────

    public function test_profile_update_with_avatar_and_name(): void
    {
        $avatar = UploadedFile::fake()->image('avatar.jpg', 50, 50);

        $response = $this->actingAs($this->user)
            ->put('/api/user', [
                'name' => 'Updated Name',
                'avatar' => $avatar,
            ]);

        $response->assertOk();
        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);
    }

    public function test_profile_update_without_images_preserves_existing(): void
    {
        $this->user->forceFill(['avatar' => 'avatars/existing.jpg'])->save();

        $response = $this->actingAs($this->user)
            ->put('/api/user', ['name' => 'New Name']);

        $response->assertOk();
        $this->user->refresh();
        $this->assertEquals('avatars/existing.jpg', $this->user->avatar);
    }

    // ─── Authentication ──────────────────────────────────────────

    public function test_profile_update_requires_authentication(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 50, 50);

        $response = $this->put('/api/user', ['avatar' => $file]);

        $response->assertUnauthorized();
    }
}
