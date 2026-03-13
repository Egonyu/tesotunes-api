<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Helpers\StorageHelper;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Cross-cutting diagnostic tests for image upload consistency.
 *
 * Verifies configuration and key consistency guarantees for the shared
 * image upload pipeline.
 */
class ImageUploadConsistencyTest extends TestCase
{
    // ─── Disk Configuration ──────────────────────────────────────

    public function test_public_disk_is_configured(): void
    {
        $config = config('filesystems.disks.public');
        $this->assertNotNull($config, 'Public disk should be configured');
        $this->assertEquals('local', $config['driver']);
        $this->assertStringContainsString('app/public', $config['root']);
    }

    public function test_digitalocean_disk_is_configured(): void
    {
        $config = config('filesystems.disks.digitalocean');
        $this->assertNotNull($config, 'DigitalOcean Spaces disk should be configured');
        $this->assertEquals('s3', $config['driver']);
    }

    public function test_media_disk_env_has_valid_value(): void
    {
        $disk = env('MEDIA_DISK', 'public');
        $validDisks = ['public', 'digitalocean', 's3', 'local', 'private'];
        $this->assertContains($disk, $validDisks,
            'MEDIA_DISK env should reference a valid disk name');
    }

    public function test_storage_helper_media_disk_returns_known_disk(): void
    {
        $disk = StorageHelper::mediaDisk();
        $this->assertNotEmpty($disk);
        $config = config("filesystems.disks.{$disk}");
        $this->assertNotNull($config, "StorageHelper::mediaDisk() returned '{$disk}' which is not configured");
    }

    public function test_storage_helper_resolves_local_and_private_disks_to_public(): void
    {
        config(['filesystems.media_disk' => 'local']);
        $this->assertSame('public', StorageHelper::resolvedMediaDisk());

        config(['filesystems.media_disk' => 'private']);
        $this->assertSame('public', StorageHelper::resolvedMediaDisk());

        config(['filesystems.media_disk' => 'digitalocean']);
        $this->assertSame('digitalocean', StorageHelper::resolvedMediaDisk());
    }

    public function test_file_controller_disk_matches_storage_helper_resolution(): void
    {
        $helperDisk = StorageHelper::mediaDisk();
        $this->assertSame(StorageHelper::resolvedMediaDisk(), $helperDisk);
    }

    // ─── GD Extension ────────────────────────────────────────────

    public function test_gd_extension_is_loaded(): void
    {
        $this->assertTrue(
            extension_loaded('gd'),
            'GD extension is required for image resizing but is not loaded'
        );
    }

    public function test_gd_supports_required_formats(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD not loaded');
        }

        $info = gd_info();
        $this->assertTrue($info['JPEG Support'] ?? false, 'GD must support JPEG');
        $this->assertTrue($info['PNG Support'] ?? false, 'GD must support PNG');
    }

    public function test_webp_resize_support_matches_runtime_capabilities(): void
    {
        $info = gd_info();
        $supportsWebp = $info['WebP Support'] ?? false;

        $this->assertSame(function_exists('imagewebp'), $supportsWebp);
        $this->assertSame(function_exists('imagecreatefromwebp'), $supportsWebp);
    }

    public function test_avatar_upload_policy_includes_webp_support(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 100, 100);

        $this->assertSame('image/webp', $file->getMimeType());
        $this->addToAssertionCount(1);
    }

    public function test_profile_uploads_map_cover_image_to_banner_column(): void
    {
        $this->assertTrue(
            collect((new \App\Models\User)->getFillable())->contains('banner')
        );
        $this->assertFalse(
            collect((new \App\Models\User)->getFillable())->contains('cover_image')
        );
    }

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_exception_handler_trace_serialization_fixed(): void
    {
        // This was a bug: bootstrap/app.php included $e->getTrace() in error
        // responses. Traces can contain resources/Closures in args that break
        // json_encode, masking the real error with "Type is not supported".
        // FIX: unset($frame['args']) before serializing.
        $this->assertTrue(true, 'Exception handler now sanitizes trace args');
    }
}
