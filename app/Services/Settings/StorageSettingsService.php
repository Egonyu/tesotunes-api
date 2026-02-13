<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;

class StorageSettingsService
{
    /**
     * Get storage settings from cache or database
     */
    public function getSettings(): array
    {
        return Cache::remember('storage_settings', 3600, function () {
            return [
                'general' => $this->getGeneralSettings(),
                'cloud' => $this->getCloudSettings(),
                'optimization' => $this->getOptimizationSettings(),
            ];
        });
    }

    /**
     * Get general storage settings
     */
    public function getGeneralSettings(): array
    {
        return [
            'storage_driver' => config('filesystems.default', 'local'),
            'user_storage_quota' => config('storage.user_quota_gb', 5),
            'max_upload_size' => config('storage.max_upload_size_mb', 100),
            'allowed_file_types' => config('storage.allowed_file_types', 'mp3,wav,flac,aac'),
            'auto_cleanup_enabled' => config('storage.auto_cleanup', false),
            'cleanup_days' => config('storage.cleanup_days', 30),
        ];
    }

    /**
     * Get cloud storage settings
     */
    public function getCloudSettings(): array
    {
        return [
            'cloud_enabled' => config('filesystems.cloud', 's3') !== 'local',
            'provider' => config('filesystems.cloud', 's3'),
            'cdn_enabled' => config('storage.cdn_enabled', false),
            'cdn_url' => config('storage.cdn_url', ''),
            's3_bucket' => config('filesystems.disks.s3.bucket', ''),
            's3_region' => config('filesystems.disks.s3.region', 'us-east-1'),
            'do_spaces_endpoint' => config('filesystems.disks.digitalocean.endpoint', ''),
            'do_spaces_bucket' => config('filesystems.disks.digitalocean.bucket', ''),
            'do_spaces_region' => config('filesystems.disks.digitalocean.region', 'nyc3'),
        ];
    }

    /**
     * Get optimization settings
     */
    public function getOptimizationSettings(): array
    {
        return [
            'image_compression' => config('storage.image_compression', true),
            'compression_quality' => config('storage.compression_quality', 85),
            'auto_transcode' => config('storage.auto_transcode', true),
            'transcode_formats' => config('storage.transcode_formats', '128kbps,320kbps'),
            'generate_thumbnails' => config('storage.generate_thumbnails', true),
            'thumbnail_sizes' => config('storage.thumbnail_sizes', '150x150,300x300,600x600'),
        ];
    }

    /**
     * Update general storage settings
     */
    public function updateGeneralSettings(array $data): bool
    {
        try {
            // Validate storage driver
            $validDrivers = ['local', 's3', 'digitalocean', 'gcs', 'azure'];
            if (isset($data['storage_driver']) && !in_array($data['storage_driver'], $validDrivers)) {
                throw new \InvalidArgumentException('Invalid storage driver');
            }

            // Update configuration dynamically
            if (isset($data['storage_driver'])) {
                config(['filesystems.default' => $data['storage_driver']]);
                $this->updateEnvFile('FILESYSTEM_DISK', $data['storage_driver']);
            }

            if (isset($data['user_storage_quota'])) {
                config(['storage.user_quota_gb' => (int)$data['user_storage_quota']]);
            }

            if (isset($data['max_upload_size'])) {
                config(['storage.max_upload_size_mb' => (int)$data['max_upload_size']]);
            }

            if (isset($data['allowed_file_types'])) {
                config(['storage.allowed_file_types' => $data['allowed_file_types']]);
            }

            if (isset($data['auto_cleanup_enabled'])) {
                config(['storage.auto_cleanup' => (bool)$data['auto_cleanup_enabled']]);
            }

            if (isset($data['cleanup_days'])) {
                config(['storage.cleanup_days' => (int)$data['cleanup_days']]);
            }

            // Clear cache
            Cache::forget('storage_settings');

            // Clear config cache
            Artisan::call('config:clear');

            return true;
        } catch (\Exception $e) {
            \Log::error('Storage settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Update cloud storage settings
     */
    public function updateCloudSettings(array $data): bool
    {
        try {
            if (isset($data['cloud_enabled'])) {
                $enabled = (bool)$data['cloud_enabled'];
                config(['storage.cloud_enabled' => $enabled]);
            }

            if (isset($data['cdn_enabled'])) {
                config(['storage.cdn_enabled' => (bool)$data['cdn_enabled']]);
            }

            if (isset($data['cdn_url'])) {
                config(['storage.cdn_url' => $data['cdn_url']]);
                $this->updateEnvFile('CDN_URL', $data['cdn_url']);
            }

            // S3 Configuration
            if (isset($data['s3_bucket'])) {
                config(['filesystems.disks.s3.bucket' => $data['s3_bucket']]);
                $this->updateEnvFile('AWS_BUCKET', $data['s3_bucket']);
            }

            if (isset($data['s3_region'])) {
                config(['filesystems.disks.s3.region' => $data['s3_region']]);
                $this->updateEnvFile('AWS_DEFAULT_REGION', $data['s3_region']);
            }

            if (isset($data['s3_key'])) {
                $this->updateEnvFile('AWS_ACCESS_KEY_ID', $data['s3_key']);
            }

            if (isset($data['s3_secret'])) {
                $this->updateEnvFile('AWS_SECRET_ACCESS_KEY', $data['s3_secret']);
            }

            // DigitalOcean Spaces Configuration
            if (isset($data['do_spaces_endpoint'])) {
                config(['filesystems.disks.digitalocean.endpoint' => $data['do_spaces_endpoint']]);
                $this->updateEnvFile('DO_SPACES_ENDPOINT', $data['do_spaces_endpoint']);
            }

            if (isset($data['do_spaces_bucket'])) {
                config(['filesystems.disks.digitalocean.bucket' => $data['do_spaces_bucket']]);
                $this->updateEnvFile('DO_SPACES_BUCKET', $data['do_spaces_bucket']);
            }

            if (isset($data['do_spaces_region'])) {
                config(['filesystems.disks.digitalocean.region' => $data['do_spaces_region']]);
                $this->updateEnvFile('DO_SPACES_REGION', $data['do_spaces_region']);
            }

            if (isset($data['do_spaces_key'])) {
                $this->updateEnvFile('DO_SPACES_KEY', $data['do_spaces_key']);
            }

            if (isset($data['do_spaces_secret'])) {
                $this->updateEnvFile('DO_SPACES_SECRET', $data['do_spaces_secret']);
            }

            // Clear cache
            Cache::forget('storage_settings');
            Artisan::call('config:clear');

            // Test connection if enabled
            if (isset($data['cloud_enabled']) && $data['cloud_enabled']) {
                $this->testStorageConnection();
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Cloud storage settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Update optimization settings
     */
    public function updateOptimizationSettings(array $data): bool
    {
        try {
            if (isset($data['image_compression'])) {
                config(['storage.image_compression' => (bool)$data['image_compression']]);
            }

            if (isset($data['compression_quality'])) {
                $quality = (int)$data['compression_quality'];
                if ($quality < 1 || $quality > 100) {
                    throw new \InvalidArgumentException('Compression quality must be between 1-100');
                }
                config(['storage.compression_quality' => $quality]);
            }

            if (isset($data['auto_transcode'])) {
                config(['storage.auto_transcode' => (bool)$data['auto_transcode']]);
            }

            if (isset($data['transcode_formats'])) {
                config(['storage.transcode_formats' => $data['transcode_formats']]);
            }

            if (isset($data['generate_thumbnails'])) {
                config(['storage.generate_thumbnails' => (bool)$data['generate_thumbnails']]);
            }

            if (isset($data['thumbnail_sizes'])) {
                config(['storage.thumbnail_sizes' => $data['thumbnail_sizes']]);
            }

            // Clear cache
            Cache::forget('storage_settings');
            Artisan::call('config:clear');

            return true;
        } catch (\Exception $e) {
            \Log::error('Optimization settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Test storage connection
     */
    public function testStorageConnection(): bool
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            
            // Try to write a test file
            $testFile = 'storage_test_' . time() . '.txt';
            $disk->put($testFile, 'Storage test');
            
            // Verify file exists
            $exists = $disk->exists($testFile);
            
            // Clean up
            $disk->delete($testFile);
            
            return $exists;
        } catch (\Exception $e) {
            \Log::error('Storage connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get storage statistics
     */
    public function getStorageStats(): array
    {
        return Cache::remember('storage_stats', 300, function () {
            try {
                $disk = Storage::disk(config('filesystems.default'));
                
                return [
                    'total_files' => $this->countFiles($disk),
                    'total_size' => $this->calculateTotalSize($disk),
                    'music_files' => $this->countMusicFiles(),
                    'image_files' => $this->countImageFiles(),
                    'disk_driver' => config('filesystems.default'),
                ];
            } catch (\Exception $e) {
                \Log::error('Failed to get storage stats', ['error' => $e->getMessage()]);
                return [
                    'total_files' => 0,
                    'total_size' => 0,
                    'music_files' => 0,
                    'image_files' => 0,
                    'disk_driver' => config('filesystems.default'),
                ];
            }
        });
    }

    /**
     * Count files in storage
     */
    private function countFiles($disk): int
    {
        try {
            return count($disk->allFiles());
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Calculate total storage size
     */
    private function calculateTotalSize($disk): int
    {
        try {
            $files = $disk->allFiles();
            $totalSize = 0;
            
            foreach ($files as $file) {
                $totalSize += $disk->size($file);
            }
            
            return $totalSize;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Count music files
     */
    private function countMusicFiles(): int
    {
        try {
            return \DB::table('songs')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Count image files
     */
    private function countImageFiles(): int
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            $files = $disk->allFiles();
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            return collect($files)->filter(function ($file) use ($imageExtensions) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                return in_array($ext, $imageExtensions);
            })->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Clean up old temporary files
     */
    public function cleanupOldFiles(int $days = 30): int
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            $threshold = now()->subDays($days)->timestamp;
            $deletedCount = 0;
            
            $files = $disk->allFiles('temp');
            
            foreach ($files as $file) {
                if ($disk->lastModified($file) < $threshold) {
                    $disk->delete($file);
                    $deletedCount++;
                }
            }
            
            \Log::info("Storage cleanup completed", ['deleted_files' => $deletedCount]);
            
            return $deletedCount;
        } catch (\Exception $e) {
            \Log::error('Storage cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Update environment file
     */
    private function updateEnvFile(string $key, string $value): void
    {
        $envFile = base_path('.env');
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $content = file_get_contents($envFile);
        $pattern = "/^{$key}=.*/m";
        
        if (preg_match($pattern, $content)) {
            // Update existing key
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            // Add new key
            $content .= "\n{$key}={$value}";
        }
        
        file_put_contents($envFile, $content);
    }

    /**
     * Format bytes to human readable size
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
