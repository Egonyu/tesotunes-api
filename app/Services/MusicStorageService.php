<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Artist;
use Carbon\Carbon;

class MusicStorageService
{
    /**
     * Available storage drivers
     */
    const DRIVER_LOCAL = 'local';
    const DRIVER_DIGITALOCEAN = 'digitalocean';

    /**
     * File access levels
     */
    const ACCESS_PRIVATE = 'private';
    const ACCESS_PUBLIC = 'public';

    private string $primaryDriver;
    private ?string $backupDriver;
    private array $config;

    public function __construct()
    {
        $this->primaryDriver = config('music.storage.primary_driver', self::DRIVER_LOCAL);
        $this->backupDriver = config('music.storage.backup_driver', null);
        $this->config = config('music.storage', []);
    }

    /**
     * Store an audio file (legacy method for backward compatibility)
     */
    public function storeAudioFile(
        UploadedFile $file,
        int $userId,
        string $artistSlug
    ): array {
        try {
            $artist = Artist::where('user_id', $userId)->firstOrFail();
            return $this->storeMusicFile($file, $artist, 'upload', self::ACCESS_PRIVATE);
        } catch (\Exception $e) {
            Log::error('Audio file storage failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Store a music file with comprehensive options
     */
    public function storeMusicFile(
        UploadedFile $file,
        Artist $artist,
        string $type = 'upload',
        string $access = self::ACCESS_PRIVATE,
        array $metadata = []
    ): array {
        $uploadInfo = $this->analyzeFile($file);
        $storagePath = $this->generateStoragePath($artist, $type, $file->getClientOriginalExtension());

        try {
            // Store on primary storage
            $primaryResult = $this->storeOnDisk($file, $storagePath, $this->primaryDriver, $access);

            // Store on backup storage if configured
            $backupResult = null;
            if ($this->backupDriver && $this->backupDriver !== $this->primaryDriver) {
                try {
                    $backupResult = $this->storeOnDisk($file, $storagePath, $this->backupDriver, $access);
                } catch (\Exception $e) {
                    Log::warning('Backup storage failed', [
                        'file' => $file->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Generate access URLs
            $urls = $this->generateAccessUrls($primaryResult['path'], $primaryResult['disk'], $access);

            return [
                'success' => true,
                'primary_storage' => $primaryResult,
                'backup_storage' => $backupResult,
                'file_info' => $uploadInfo,
                'storage_path' => $storagePath,
                'access_urls' => $urls,
                'metadata' => array_merge($metadata, [
                    'stored_at' => now()->toISOString(),
                    'storage_driver' => $this->primaryDriver,
                    'has_backup' => !is_null($backupResult),
                ])
            ];

        } catch (\Exception $e) {
            Log::error('Music file storage failed', [
                'file' => $file->getClientOriginalName(),
                'artist_id' => $artist->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'file_info' => $uploadInfo
            ];
        }
    }

    /**
     * Store artwork/images
     */
    public function storeArtwork(
        UploadedFile $file,
        Artist $artist,
        string $type = 'cover',
        string $access = self::ACCESS_PUBLIC
    ): array {
        $uploadInfo = $this->analyzeFile($file);

        // Validate image
        if (!$this->isValidImage($file)) {
            return [
                'success' => false,
                'error' => 'Invalid image format or corrupted file'
            ];
        }

        $storagePath = $this->generateArtworkPath($artist, $type, $file->getClientOriginalExtension());

        try {
            $disk = $access === self::ACCESS_PUBLIC ? 'music_public' : 'music_private';

            // Store image
            $result = $this->storeOnDisk($file, $storagePath, $disk, $access);
            $urls = $this->generateAccessUrls($result['path'], $result['disk'], $access);

            return [
                'success' => true,
                'storage' => $result,
                'storage_path' => $storagePath, // Add for consistency
                'file_info' => $uploadInfo,
                'access_urls' => $urls,
            ];

        } catch (\Exception $e) {
            Log::error('Artwork storage failed', [
                'file' => $file->getClientOriginalName(),
                'artist_id' => $artist->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Store file on specific disk
     */
    private function storeOnDisk(UploadedFile $file, string $path, string $disk, string $access): array
    {
        // Validate path
        if (empty($path)) {
            throw new \Exception('Storage path cannot be empty');
        }

        $diskInstance = $this->getDiskInstance($disk);

        // Configure visibility
        $visibility = $access === self::ACCESS_PUBLIC ? 'public' : 'private';

        // Get directory path - ensure it's not empty
        $directory = dirname($path);
        if ($directory === '.' || $directory === '') {
            $directory = '';
        }

        // Store the file
        $storedPath = $diskInstance->putFileAs(
            $directory,
            $file,
            basename($path),
            $visibility
        );

        if (!$storedPath) {
            throw new \Exception("Failed to store file on disk: {$disk}");
        }

        return [
            'disk' => $disk,
            'path' => $storedPath,
            'full_path' => $diskInstance->path($storedPath),
            'size' => $diskInstance->size($storedPath),
            'url' => $diskInstance->url($storedPath),
            'visibility' => $visibility,
            'stored_at' => now()->toISOString()
        ];
    }

    /**
     * Generate storage path for music files
     */
    private function generateStoragePath(Artist $artist, string $type, string $extension): string
    {
        $year = Carbon::now()->year;
        $month = Carbon::now()->format('m');
        $artistSlug = Str::slug($artist->name);
        $filename = $this->generateUniqueFilename($extension);

        return "music/{$type}s/{$year}/{$month}/{$artistSlug}/{$filename}";
    }

    /**
     * Generate artwork storage path
     */
    private function generateArtworkPath(Artist $artist, string $type, string $extension): string
    {
        $artistSlug = Str::slug($artist->name);
        $filename = $this->generateUniqueFilename($extension);

        return "artwork/{$type}/{$artistSlug}/{$filename}";
    }

    /**
     * Generate unique filename
     */
    private function generateUniqueFilename(string $extension): string
    {
        return time() . '_' . Str::random(16) . '.' . $extension;
    }

    /**
     * Analyze uploaded file
     */
    private function analyzeFile(UploadedFile $file): array
    {
        // Get the real path and validate it
        $realPath = $file->getRealPath();
        
        if (empty($realPath) || !file_exists($realPath)) {
            Log::error('File real path is empty or does not exist', [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'error' => $file->getError(),
                'error_message' => $file->getErrorMessage(),
                'is_valid' => $file->isValid(),
            ]);
            
            throw new \Exception('Uploaded file is not accessible. Error: ' . $file->getErrorMessage());
        }

        $fileInfo = [
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
            'hash' => hash_file('sha256', $realPath),
            'is_valid' => $file->isValid(),
            'duration' => 0, // Default to 0
        ];

        // Extract duration for audio files using getID3 if available
        if ($this->isAudioFile($file->getClientOriginalName())) {
            $fileInfo['duration'] = $this->extractAudioDuration($realPath);
        }

        return $fileInfo;
    }

    /**
     * Extract audio duration from file
     */
    private function extractAudioDuration(string $filePath): int
    {
        try {
            // Try using getID3 library if available
            if (class_exists('\getID3')) {
                $getID3 = new \getID3();
                $fileInfo = $getID3->analyze($filePath);
                
                if (isset($fileInfo['playtime_seconds'])) {
                    return (int) round($fileInfo['playtime_seconds']);
                }
            }

            // Try using FFmpeg/FFprobe if available
            if (function_exists('shell_exec')) {
                $duration = $this->extractDurationWithFFprobe($filePath);
                if ($duration > 0) {
                    return $duration;
                }
            }

            Log::warning('Could not extract audio duration - no extraction method available', [
                'file' => $filePath
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to extract audio duration', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }

        return 0;
    }

    /**
     * Extract duration using FFprobe
     */
    private function extractDurationWithFFprobe(string $filePath): int
    {
        try {
            $escapedPath = escapeshellarg($filePath);
            $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {$escapedPath} 2>&1";
            
            $output = shell_exec($command);
            
            if ($output && is_numeric(trim($output))) {
                return (int) round((float) trim($output));
            }
        } catch (\Exception $e) {
            Log::debug('FFprobe extraction failed', ['error' => $e->getMessage()]);
        }

        return 0;
    }

    /**
     * Validate image file
     */
    private function isValidImage(UploadedFile $file): bool
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        return in_array($file->getMimeType(), $allowedMimes) &&
               in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions) &&
               $file->isValid();
    }

    /**
     * Get disk instance
     */
    private function getDiskInstance(string $disk): \Illuminate\Filesystem\FilesystemAdapter
    {
        // Map disk names to actual configuration
        $diskMap = [
            self::DRIVER_LOCAL => 'music_private',
            self::DRIVER_DIGITALOCEAN => 'digitalocean',
            'local_public' => 'music_public',
        ];

        $actualDisk = $diskMap[$disk] ?? $disk;

        return Storage::disk($actualDisk);
    }

    /**
     * Generate access URLs for different use cases
     */
    private function generateAccessUrls(string $path, string $disk, string $access): array
    {
        $diskInstance = $this->getDiskInstance($disk);
        $urls = [];

        try {
            if ($access === self::ACCESS_PUBLIC) {
                $urls['public'] = $diskInstance->url($path);
            } else {
                // Generate temporary URLs for private files
                $urls['temporary'] = $diskInstance->temporaryUrl(
                    $path,
                    now()->addHours(24)
                );
            }

            // Add streaming URL for audio files
            if ($this->isAudioFile($path)) {
                $urls['streaming'] = $this->generateStreamingUrl($path, $disk);
            }

            // Add download URL
            $urls['download'] = $this->generateDownloadUrl($path, $disk);

        } catch (\Exception $e) {
            Log::warning('Failed to generate access URLs', [
                'path' => $path,
                'disk' => $disk,
                'error' => $e->getMessage()
            ]);
        }

        return $urls;
    }

    /**
     * Check if file is audio
     */
    private function isAudioFile(string $path): bool
    {
        $audioExtensions = ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, $audioExtensions);
    }

    /**
     * Generate streaming URL
     */
    private function generateStreamingUrl(string $path, string $disk): string
    {
        // This would integrate with your streaming service or generate a signed URL
        return route('frontend.music.stream', [
            'file' => encrypt($path),
            'disk' => encrypt($disk)
        ]);
    }

    /**
     * Generate download URL
     */
    private function generateDownloadUrl(string $path, string $disk): string
    {
        return route('frontend.music.download', [
            'file' => encrypt($path),
            'disk' => encrypt($disk)
        ]);
    }

    /**
     * Delete file from storage
     */
    public function deleteFile(string $path, string $disk = null): bool
    {
        $disk = $disk ?: $this->primaryDriver;

        try {
            $diskInstance = $this->getDiskInstance($disk);

            if ($diskInstance->exists($path)) {
                return $diskInstance->delete($path);
            }

            return true; // File doesn't exist, consider it deleted

        } catch (\Exception $e) {
            Log::error('Failed to delete file', [
                'path' => $path,
                'disk' => $disk,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Move file between storage systems
     */
    public function moveFile(string $sourcePath, string $sourceDisk, string $targetDisk): array
    {
        try {
            $sourceInstance = $this->getDiskInstance($sourceDisk);
            $targetInstance = $this->getDiskInstance($targetDisk);

            if (!$sourceInstance->exists($sourcePath)) {
                throw new \Exception("Source file does not exist: {$sourcePath}");
            }

            // Read from source
            $fileContent = $sourceInstance->get($sourcePath);

            // Write to target
            $success = $targetInstance->put($sourcePath, $fileContent);

            if ($success) {
                // Delete from source after successful copy
                $sourceInstance->delete($sourcePath);

                return [
                    'success' => true,
                    'source_disk' => $sourceDisk,
                    'target_disk' => $targetDisk,
                    'path' => $sourcePath
                ];
            } else {
                throw new \Exception("Failed to write file to target disk");
            }

        } catch (\Exception $e) {
            Log::error('Failed to move file', [
                'source_path' => $sourcePath,
                'source_disk' => $sourceDisk,
                'target_disk' => $targetDisk,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get file information
     */
    public function getFileInfo(string $path, string $disk = null): array
    {
        $disk = $disk ?: $this->primaryDriver;

        try {
            $diskInstance = $this->getDiskInstance($disk);

            if (!$diskInstance->exists($path)) {
                throw new \Exception("File does not exist: {$path}");
            }

            return [
                'exists' => true,
                'size' => $diskInstance->size($path),
                'last_modified' => $diskInstance->lastModified($path),
                'mime_type' => $diskInstance->mimeType($path),
                'visibility' => $diskInstance->getVisibility($path),
                'url' => $diskInstance->url($path),
                'disk' => $disk,
                'path' => $path
            ];

        } catch (\Exception $e) {
            return [
                'exists' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get storage statistics
     */
    public function getStorageStats(): array
    {
        $stats = [];

        foreach ([self::DRIVER_LOCAL, self::DRIVER_DIGITALOCEAN] as $driver) {
            try {
                $diskInstance = $this->getDiskInstance($driver);
                $files = $diskInstance->allFiles('music');

                $totalSize = 0;
                $fileCount = count($files);

                foreach ($files as $file) {
                    $totalSize += $diskInstance->size($file);
                }

                $stats[$driver] = [
                    'file_count' => $fileCount,
                    'total_size_bytes' => $totalSize,
                    'total_size_mb' => round($totalSize / (1024 * 1024), 2),
                    'total_size_gb' => round($totalSize / (1024 * 1024 * 1024), 2),
                ];

            } catch (\Exception $e) {
                $stats[$driver] = [
                    'error' => $e->getMessage()
                ];
            }
        }

        return $stats;
    }
}