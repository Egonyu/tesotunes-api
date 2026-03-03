<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Helpers\StorageHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for the StorageHelper class — the centralised upload/URL helper.
 *
 * IMPORTANT: StorageHelper::store() on local ('public') disk uses
 * $file->move() to storage_path('app/public/...'), bypassing the
 * Storage facade. Therefore Storage::fake() is NOT used for store() tests.
 *
 * Bugs documented:
 *  - mediaDisk() reads raw env() instead of config(), making it un-testable
 *    unless we use putenv(). Config overrides in testing are ignored.
 */
class StorageHelperTest extends TestCase
{
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            $fullPath = storage_path('app/public/'.$path);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
        foreach (array_unique(array_map(fn ($p) => dirname(storage_path('app/public/'.$p)), array_reverse($this->createdFiles))) as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    // ─── store() ──────────────────────────────────────────────────

    public function test_store_saves_file_and_returns_path(): void
    {
        $file = UploadedFile::fake()->image('album-cover.jpg', 100, 100);
        $path = StorageHelper::store($file, 'test_albums/artwork');
        $this->createdFiles[] = $path;

        $this->assertStringStartsWith('test_albums/artwork/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertFileExists(storage_path('app/public/'.$path));
    }

    public function test_store_generates_unique_filenames(): void
    {
        $file1 = UploadedFile::fake()->image('cover.png', 50, 50);
        $path1 = StorageHelper::store($file1, 'test_uniq');
        $this->createdFiles[] = $path1;

        $file2 = UploadedFile::fake()->image('cover.png', 50, 50);
        $path2 = StorageHelper::store($file2, 'test_uniq');
        $this->createdFiles[] = $path2;

        $this->assertNotEquals($path1, $path2);
    }

    public function test_store_uses_custom_filename(): void
    {
        $file = UploadedFile::fake()->image('anything.jpg', 50, 50);
        $path = StorageHelper::store($file, 'test_cust', 'custom.jpg');
        $this->createdFiles[] = $path;

        $this->assertEquals('test_cust/custom.jpg', $path);
    }

    public function test_store_creates_subdirectory(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 50, 50);
        $path = StorageHelper::store($file, 'test_deep/nested/dir');
        $this->createdFiles[] = $path;

        $this->assertFileExists(storage_path('app/public/'.$path));
    }

    public function test_store_sanitises_special_characters(): void
    {
        $file = UploadedFile::fake()->image('my photo (1).jpg', 50, 50);
        $path = StorageHelper::store($file, 'test_san');
        $this->createdFiles[] = $path;

        $this->assertDoesNotMatchRegularExpression('/[\(\)\s]/', basename($path));
    }

    public function test_store_handles_file_without_extension(): void
    {
        $file = UploadedFile::fake()->create('noext', 512, 'image/jpeg');
        $path = StorageHelper::store($file, 'test_noext');
        $this->createdFiles[] = $path;

        $this->assertStringStartsWith('test_noext/', $path);
    }

    // ─── delete() ─────────────────────────────────────────────────

    public function test_delete_removes_file(): void
    {
        Storage::disk('public')->put('test_del/file.jpg', 'content');
        StorageHelper::delete('test_del/file.jpg');
        Storage::disk('public')->assertMissing('test_del/file.jpg');
    }

    public function test_delete_ignores_null_and_empty(): void
    {
        StorageHelper::delete(null);
        StorageHelper::delete('');
        $this->assertTrue(true);
    }

    public function test_delete_skips_external_urls(): void
    {
        StorageHelper::delete('https://cdn.example.com/image.jpg');
        $this->assertTrue(true);
    }

    // ─── url() ────────────────────────────────────────────────────

    public function test_url_returns_null_for_empty_path(): void
    {
        $this->assertNull(StorageHelper::url(null));
        $this->assertNull(StorageHelper::url(''));
    }

    public function test_url_returns_external_url_as_is(): void
    {
        $this->assertEquals(
            'https://cdn.tesotunes.com/image.jpg',
            StorageHelper::url('https://cdn.tesotunes.com/image.jpg')
        );
    }

    public function test_url_returns_absolute_url_for_local_path(): void
    {
        $url = StorageHelper::url('avatars/test.jpg');
        $this->assertNotNull($url);
        $this->assertStringStartsWith('http', $url);
    }

    // ─── artworkUrl() / avatarUrl() ──────────────────────────────

    public function test_artwork_url_returns_url_when_present(): void
    {
        $this->assertNotNull(StorageHelper::artworkUrl('songs/cover.jpg'));
    }

    public function test_artwork_url_returns_default_when_empty(): void
    {
        $url = StorageHelper::artworkUrl(null, 'images/default.png');
        $this->assertStringContainsString('default.png', $url);
    }

    public function test_artwork_url_returns_null_when_both_empty(): void
    {
        $this->assertNull(StorageHelper::artworkUrl(null, null));
    }

    public function test_avatar_url_returns_ui_avatars_fallback(): void
    {
        $url = StorageHelper::avatarUrl(null, 'John Doe');
        $this->assertStringContainsString('ui-avatars.com', $url);
    }

    public function test_media_disk_defaults_to_public(): void
    {
        $this->assertEquals('public', StorageHelper::mediaDisk());
    }
}
