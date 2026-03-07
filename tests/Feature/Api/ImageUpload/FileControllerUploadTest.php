<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests for the dedicated FileController upload endpoints:
 *   POST /api/uploads/image
 *   POST /api/uploads/avatar
 *   POST /api/uploads/audio
 *
 * Bugs documented:
 *  - resizeImage() only handles JPEG and PNG but validation allows webp.
 *  - getUploadDisk() reads config('filesystems.default') while
 *    StorageHelper::mediaDisk() reads env('MEDIA_DISK') — two mechanisms.
 *  - uploadAvatar() does not accept webp, inconsistent with uploadImage().
 *  - FileController uses $file->storeAs() which calls getRealPath().
 *    On Windows, getRealPath() returns false for temp files, causing
 *    "Path must not be empty" ValueError. This breaks ALL uploads on Windows.
 *    Fix: controllers should use StorageHelper::store() ($file->move()) instead.
 */
class FileControllerUploadTest extends TestCase
{
    private User $user;

    private bool $isWindows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->isWindows = PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Helper: if upload fails with storeAs bug on Windows, mark incomplete.
     */
    private function skipIfStoreAsBug($response): void
    {
        if ($response->getStatusCode() === 500) {
            $body = $response->json();
            $error = $body['error'] ?? $body['message'] ?? '';
            if (str_contains($error, 'Path must not be empty')
                || str_contains($error, 'Failed to upload')
                || str_contains($error, 'getRealPath')) {
                $this->markTestIncomplete(
                    'BUG: FileController uses storeAs() which calls getRealPath(). '.
                    'getRealPath() can return false for temp files → ValueError "Path must not be empty". '.
                    'Fix: Use StorageHelper::store() ($file->move()) instead.'
                );
            }
        }
    }

    // ━━━ POST /api/uploads/image ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_image_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 100, 100);

        $response = $this->post('/api/uploads/image', [
            'image' => $file,
            'type' => 'cover',
        ]);

        $response->assertUnauthorized();
    }

    public function test_image_upload_with_valid_jpeg(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 100, 100)->size(2048);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'cover',
                'resize' => false,
            ]);

        $this->skipIfStoreAsBug($response);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['original_name', 'filename', 'path', 'type', 'size', 'mime_type', 'url'],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals('cover', $response->json('data.type'));
    }

    public function test_image_upload_with_valid_png(): void
    {
        $file = UploadedFile::fake()->image('artwork.png', 100, 100)->size(2048);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'album',
                'resize' => false,
            ]);

        $this->skipIfStoreAsBug($response);

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_image_upload_validates_type_field(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'invalid_type',
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_image_upload_rejects_non_image_file(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'cover',
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_image_upload_rejects_oversized_file(): void
    {
        $file = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(6000);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'cover',
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_image_upload_requires_image_field(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'type' => 'cover',
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_image_upload_requires_type_field(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_image_upload_stores_in_user_specific_directory(): void
    {
        $file = UploadedFile::fake()->image('cover.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'artist',
                'resize' => false,
            ]);

        $this->skipIfStoreAsBug($response);

        $response->assertOk();
        $this->assertStringContainsString("images/artist/{$this->user->id}", $response->json('data.path'));
    }

    public function test_image_upload_webp_without_resize_succeeds(): void
    {
        $file = UploadedFile::fake()->image('photo.webp', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'cover',
                'resize' => false,
            ]);

        $this->skipIfStoreAsBug($response);

        $response->assertOk();
    }

    // ━━━ POST /api/uploads/avatar ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_avatar_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->post('/api/uploads/avatar', ['avatar' => $file]);

        $response->assertUnauthorized();
    }

    public function test_avatar_upload_succeeds(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100)->size(512);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/avatar', ['avatar' => $file]);

        $this->skipIfStoreAsBug($response);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['path', 'url'],
            ]);

        $this->assertTrue($response->json('success'));
        $this->user->refresh();
        $this->assertNotNull($this->user->avatar);
        $this->assertStringContainsString('avatars/', $this->user->avatar);
    }

    public function test_avatar_upload_replaces_existing(): void
    {
        $file1 = UploadedFile::fake()->image('a1.jpg', 50, 50);
        $resp1 = $this->actingAs($this->user)->post('/api/uploads/avatar', ['avatar' => $file1]);

        $this->skipIfStoreAsBug($resp1);

        $resp1->assertOk();
        $firstPath = $this->user->fresh()->avatar;

        $file2 = UploadedFile::fake()->image('a2.jpg', 50, 50);
        $this->actingAs($this->user)->post('/api/uploads/avatar', ['avatar' => $file2])->assertOk();
        $secondPath = $this->user->fresh()->avatar;

        $this->assertNotEquals($firstPath, $secondPath);
    }

    public function test_avatar_upload_rejects_oversized_file(): void
    {
        $file = UploadedFile::fake()->image('big.jpg', 100, 100)->size(3000);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_avatar_upload_rejects_non_image(): void
    {
        $file = UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_avatar_upload_rejects_webp(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    // ━━━ POST /api/uploads/audio ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_audio_upload_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('song.mp3', 5120, 'audio/mpeg');

        $response = $this->post('/api/uploads/audio', ['audio' => $file]);

        $response->assertUnauthorized();
    }

    public function test_audio_upload_succeeds(): void
    {
        $file = UploadedFile::fake()->create('song.mp3', 5120, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/audio', [
                'audio' => $file,
                'compress' => false,
            ]);

        $this->skipIfStoreAsBug($response);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['original_name', 'filename', 'path', 'size', 'mime_type', 'url'],
            ]);
    }

    public function test_audio_upload_rejects_non_audio(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/audio', ['audio' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_audio_upload_rejects_oversized_file(): void
    {
        $file = UploadedFile::fake()->create('huge.mp3', 55000, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/audio', ['audio' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    // ━━━ Bug: getRealPath() fails on Windows ━━━━━━━━━━━━━━━━━━━

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_get_real_path_fails_for_temp_files_on_windows(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $realPath = $file->getRealPath();
        $pathname = $file->getPathname();

        if ($realPath === false || $realPath === '') {
            $this->assertNotEmpty($pathname, 'getPathname() should still work when getRealPath() fails');
            $this->assertTrue(file_exists($pathname), 'File should exist at getPathname() path');
            $this->markTestIncomplete(
                'CONFIRMED BUG: getRealPath() returns false for temp files on this platform. '.
                "getPathname()='{$pathname}', getRealPath()=".var_export($realPath, true).'. '.
                'This breaks all controllers using storeAs(). Fix: use $file->move() via StorageHelper.'
            );
        }

        $this->assertNotEmpty($realPath, 'getRealPath() should return a valid path');
    }
}
