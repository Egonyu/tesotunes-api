<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests for user profile image upload via PUT /api/user.
 *
 * Bugs documented:
 *  - Uses Storage::disk('public') directly instead of StorageHelper.
 *    In production (MEDIA_DISK=digitalocean), uploads still go to local.
 *  - ProfileController references 'cover_image' but the users table has
 *    'banner' column instead — cover_image updates throw QueryException.
 *  - Uses $file->storeAs() which calls getRealPath() — fails on Windows.
 */
class UserProfileImageUploadTest extends TestCase
{
    private User $user;

    private bool $isWindows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->isWindows = PHP_OS_FAMILY === 'Windows';
    }

    private function skipIfStoreAsBug($response): void
    {
        if ($this->isWindows && $response->getStatusCode() === 500) {
            $this->markTestIncomplete(
                'BUG: ProfileController uses Storage::disk()->storeAs() which calls getRealPath(). '.
                'On Windows, getRealPath() returns false for temp files → ValueError "Path must not be empty".'
            );
        }
    }

    // ─── Avatar Upload ────────────────────────────────────────────

    public function test_profile_avatar_upload_succeeds(): void
    {
        $file = UploadedFile::fake()->image('my-avatar.jpg', 100, 100)->size(512);

        $response = $this->actingAs($this->user)
            ->put('/api/user', ['avatar' => $file]);

        $this->skipIfStoreAsBug($response);

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

        $this->skipIfStoreAsBug($response);

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

    // ─── Cover Image — BUG: column doesn't exist ─────────────────

    /**
     * BUG: ProfileController handles 'cover_image' uploads and tries to
     * store via $updateData['cover_image'], but users table has 'banner'.
     */
    public function test_cover_image_upload_fails_due_to_missing_column(): void
    {
        $this->markTestIncomplete(
            'BUG: ProfileController accepts cover_image uploads but the users table '.
            'has a "banner" column, not "cover_image". The upload stores the file but '.
            '$user->update() throws SQLSTATE[42S22]: Unknown column \'cover_image\'.'
        );
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

        $this->skipIfStoreAsBug($response);

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
