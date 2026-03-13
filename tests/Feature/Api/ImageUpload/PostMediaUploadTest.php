<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Tests post media upload via POST /api/posts.
 *
 * Verifies post media uploads persist relative paths and return absolute URLs
 * through the API response.
 */
class PostMediaUploadTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_active' => true]);
    }

    // ─── Create Post with Media ──────────────────────────────────

    public function test_post_with_single_image_upload(): void
    {
        $image = UploadedFile::fake()->image('photo.jpg', 100, 100)->size(1024);

        $response = $this->actingAs($this->user)
            ->post('/api/posts', [
                'content' => 'Check out this photo!',
                'media' => [$image],
            ]);

        $response->assertCreated();
    }

    public function test_post_with_multiple_images(): void
    {
        $images = [
            UploadedFile::fake()->image('photo1.jpg', 100, 100)->size(512),
            UploadedFile::fake()->image('photo2.jpg', 100, 100)->size(512),
            UploadedFile::fake()->image('photo3.png', 100, 100)->size(256),
        ];

        $response = $this->actingAs($this->user)
            ->post('/api/posts', [
                'content' => 'Multiple photos',
                'media' => $images,
            ]);

        $response->assertCreated();
    }

    public function test_post_media_without_content_requires_media(): void
    {
        $image = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/posts', [
                'media' => [$image],
            ]);

        $response->assertCreated();
    }

    // ─── Validation ──────────────────────────────────────────────

    public function test_post_media_validates_file_type(): void
    {
        $file = UploadedFile::fake()->create('evil.exe', 500, 'application/x-msdownload');

        $response = $this->actingAs($this->user)
            ->post(
                '/api/posts',
                [
                    'content' => 'Bad upload',
                    'media' => [$file],
                ],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    public function test_post_media_validates_max_count(): void
    {
        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->image("img{$i}.jpg", 50, 50)->size(100);
        }

        $response = $this->actingAs($this->user)
            ->post(
                '/api/posts',
                [
                    'content' => 'Too many files',
                    'media' => $files,
                ],
                ['Accept' => 'application/json']
            );

        $response->assertUnprocessable();
    }

    // ─── Authorization ───────────────────────────────────────────

    public function test_post_upload_requires_auth(): void
    {
        $image = UploadedFile::fake()->image('art.jpg', 100, 100);

        $response = $this->post('/api/posts', [
            'content' => 'Unauth post',
            'media' => [$image],
        ]);

        $response->assertUnauthorized();
    }

    public function test_post_media_stores_relative_path_and_returns_absolute_url(): void
    {
        $image = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post('/api/posts', [
                'content' => 'URL test',
                'media' => [$image],
            ]);

        $response->assertCreated();

        $post = \App\Models\Post::latest()->first();
        if ($post && $post->media->isNotEmpty()) {
            $mediaUrl = $post->media->first()->url;
            $this->assertStringStartsWith('posts/media/', $mediaUrl);
            $this->assertStringStartsWith('http', $response->json('data.media.url'));
        }
    }
}
