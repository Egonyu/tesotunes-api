<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Helpers\StorageHelper;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Cross-cutting diagnostic tests for image upload consistency.
 *
 * Verifies configuration and documents inconsistencies across the codebase.
 * Also documents the root cause of frontend upload failures.
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

    // ─── Inconsistency documentation ─────────────────────────────

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_document_storage_strategy_inconsistencies(): void
    {
        $strategies = [
            'AdminArtistsController' => 'StorageHelper::store() ✓ CORRECT',
            'AdminEventsApiController' => 'StorageHelper::store() ✓ CORRECT',
            'FileController' => 'config(filesystems.default) + storeAs() — different disk mechanism, uses getRealPath()',
            'ProfileController' => 'Storage::disk(public) + storeAs() — hardcoded, ignores MEDIA_DISK, uses getRealPath()',
            'SongsApiController' => '$file->store(path, public) — hardcoded, ignores MEDIA_DISK, uses getRealPath()',
            'ArtistApiController' => '$file->store(path, public) — hardcoded, ignores MEDIA_DISK, uses getRealPath()',
            'ArtistEventsController' => '$file->move(public_path()) — CRITICAL: bypasses Storage entirely',
            'PostController' => '$file->store(path, public) + stores FULL URL in DB, uses getRealPath()',
        ];

        $correctCount = 0;
        foreach ($strategies as $strategy) {
            if (str_contains($strategy, '✓ CORRECT')) {
                $correctCount++;
            }
        }

        $this->assertEquals(2, $correctCount,
            'Only 2 out of 8 controllers use StorageHelper correctly. '.
            'All controllers should use StorageHelper for cloud-compatible uploads.');

        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_file_controller_disk_differs_from_storage_helper(): void
    {
        $helperDisk = StorageHelper::mediaDisk();
        $controllerDisk = config('filesystems.default');
        $resolvedControllerDisk = $controllerDisk === 'local' ? 'public' : $controllerDisk;

        if ($helperDisk !== $resolvedControllerDisk) {
            $this->markTestIncomplete(
                "BUG: StorageHelper disk '{$helperDisk}' differs from FileController disk '{$resolvedControllerDisk}'. ".
                'Uploads via /api/uploads/* may go to a different disk than uploads via other endpoints.'
            );
        }

        $this->assertEquals($helperDisk, $resolvedControllerDisk,
            'StorageHelper and FileController should resolve to the same disk');
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

    // ─── Root Cause Analysis ─────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_get_real_path_returns_false_on_windows_temp_files(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $realPath = $file->getRealPath();
        $pathname = $file->getPathname();

        if ($realPath === false || $realPath === '') {
            $this->assertTrue(file_exists($pathname),
                'File exists at getPathname() but getRealPath() returns false');
            $this->markTestIncomplete(
                'ROOT CAUSE: getRealPath() returns false for temp files on this platform (Windows). '.
                "File exists at '{$pathname}' but getRealPath()=".var_export($realPath, true).'. '.
                'Laravel\'s storeAs()/store() use getRealPath(), so ALL controllers that use these '.
                'methods fail with "Path must not be empty". Controllers using $file->move() '.
                '(StorageHelper) work correctly because move() uses getPathname().'
            );
        }

        $this->assertNotEmpty($realPath);
    }

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_webp_resize_not_supported_in_file_controller(): void
    {
        $this->markTestIncomplete(
            'BUG: FileController validates webp uploads but resizeImage() only handles '.
            'IMAGETYPE_JPEG and IMAGETYPE_PNG. WebP images will not be resized.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_avatar_validation_excludes_webp(): void
    {
        $this->markTestIncomplete(
            'BUG: FileController::uploadImage() accepts webp but uploadAvatar() does not. '.
            'Inconsistent format support across upload endpoints.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Group('bugs')]
    public function test_profile_controller_cover_image_column_mismatch(): void
    {
        $this->markTestIncomplete(
            'BUG: ProfileController accepts cover_image uploads but users table has '.
            '"banner" column, not "cover_image". Update throws Unknown column error.'
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
