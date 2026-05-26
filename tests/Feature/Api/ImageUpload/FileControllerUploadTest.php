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
 * Verifies the dedicated upload endpoints use the shared storage strategy
 * and support the same modern image formats across routes.
 */
class FileControllerUploadTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_active' => true]);
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

    public function test_avatar_upload_accepts_webp(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/avatar', ['avatar' => $file], ['Accept' => 'application/json']);

        $response->assertOk();
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
        $oversizedKilobytes = (int) ceil((int) config('music.storage.limits.max_audio_size', 500 * 1024 * 1024) / 1024) + 1;
        $file = UploadedFile::fake()->create('huge.mp3', $oversizedKilobytes, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/audio', ['audio' => $file], ['Accept' => 'application/json']);

        $response->assertUnprocessable();
    }

    public function test_uploads_work_without_get_real_path_failures(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'cover',
                'resize' => false,
            ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.path'));
    }

    public function test_branding_image_upload_succeeds(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'branding',
                'resize' => false,
            ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['url', 'path', 'type']]);

        $this->assertEquals('branding', $response->json('data.type'));
        $this->assertStringContainsString('images/branding/', $response->json('data.path'));
        $this->assertStringStartsWith('http', $response->json('data.url'));
    }

    public function test_branding_image_stored_in_platform_directory_not_user(): void
    {
        $file = UploadedFile::fake()->image('favicon.png', 32, 32);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'branding',
                'resize' => false,
            ]);

        $response->assertOk();
        $path = $response->json('data.path');
        // Branding files must NOT be scoped to a user directory
        $this->assertStringNotContainsString((string) $this->user->id, $path);
        $this->assertStringStartsWith('images/branding/', $path);
    }

    public function test_ad_image_upload_succeeds(): void
    {
        $file = UploadedFile::fake()->image('banner.jpg', 728, 90);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'ad',
                'resize' => false,
            ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['url', 'path', 'type']]);

        $this->assertEquals('ad', $response->json('data.type'));
        $this->assertStringStartsWith('http', $response->json('data.url'));
    }

    public function test_branding_image_url_is_absolute(): void
    {
        $file = UploadedFile::fake()->image('logo.jpg', 200, 50);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'branding',
                'resize' => false,
            ]);

        $response->assertOk();
        $url = $response->json('data.url');
        $this->assertNotNull($url);
        $this->assertStringStartsWith('http', $url, 'URL must be absolute, got: '.$url);
    }

    // ━━━ Multipart boolean coercion ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // A browser FormData upload can only send strings. The `boolean`
    // validation rule accepts "0"/"1" but rejects "true"/"false",
    // so the frontend must send "0"/"1" for the `resize` flag.

    public function test_image_upload_accepts_resize_as_string_zero(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'branding',
                'resize' => '0',
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_image_upload_rejects_resize_as_string_false(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($this->user)
            ->post('/api/uploads/image', [
                'image' => $file,
                'type' => 'branding',
                'resize' => 'false',
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('resize');
    }
}
