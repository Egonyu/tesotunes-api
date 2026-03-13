<?php

namespace App\Http\Controllers\Api\Upload;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FileController extends Controller
{
    /**
     * Get the configured storage disk name for uploads.
     * Uses FILESYSTEM_DISK env var, defaults to 'public' for backward compatibility.
     */
    private function getUploadDisk(): string
    {
        return StorageHelper::resolvedMediaDisk();
    }

    /**
     * Get a Storage disk instance for uploads.
     */
    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->getUploadDisk());
    }

    /**
     * Check if the configured upload disk is a local driver.
     * Compression and image resizing require local filesystem access.
     */
    private function isLocalDisk(): bool
    {
        $diskName = $this->getUploadDisk();
        $driver = config("filesystems.disks.{$diskName}.driver", 'local');

        return $driver === 'local';
    }

    public function uploadAudio(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'audio' => 'required|file|mimes:mp3,wav,flac,m4a,aac|max:51200', // 50MB max
                'compress' => 'boolean',
                'quality' => 'nullable|in:low,medium,high',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $file = $request->file('audio');
            $user = auth()->user();
            $uploadDisk = $this->getUploadDisk();

            // Generate unique filename
            $filename = time().'_'.Str::random(10).'.'.$file->getClientOriginalExtension();
            $directory = 'audio/'.$user->id;

            // Store original file on configured disk
            // Use put() with stream to avoid getRealPath() returning false on temp files
            $storedPath = $directory.'/'.$filename;
            $this->disk()->put($storedPath, fopen($file->getPathname(), 'r'));

            $fileData = [
                'original_name' => $file->getClientOriginalName(),
                'filename' => $filename,
                'path' => $storedPath,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'url' => $this->disk()->url($storedPath),
            ];

            // Create compressed version if requested (for African market data efficiency)
            // Note: Compression only works with local storage (needs filesystem path)
            if ($request->boolean('compress', true) && $this->isLocalDisk()) {
                try {
                    $compressedPath = $this->compressAudio($storedPath, $request->get('quality', 'medium'));
                    $fileData['compressed_path'] = $compressedPath;
                    $fileData['compressed_url'] = $this->disk()->url($compressedPath);
                } catch (\Exception $e) {
                    // If compression fails, continue with original file
                    \Log::warning('Audio compression failed: '.$e->getMessage());
                }
            }

            // Extract audio metadata (only works with local storage)
            if ($this->isLocalDisk()) {
                $metadata = $this->extractAudioMetadata($storedPath);
                $fileData = array_merge($fileData, $metadata);
            }

            return response()->json([
                'success' => true,
                'message' => 'Audio uploaded successfully',
                'data' => $fileData,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload audio',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadImage(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // 5MB max
                'type' => 'required|in:cover,album,artist,playlist',
                'resize' => 'boolean',
                'width' => 'nullable|integer|min:100|max:2000',
                'height' => 'nullable|integer|min:100|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $file = $request->file('image');
            $user = auth()->user();
            $type = $request->type;
            $originalName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();

            // Generate unique filename
            $filename = time().'_'.Str::random(10).'.'.$file->getClientOriginalExtension();
            $directory = "images/{$type}/".$user->id;

            // Store original image on configured media disk.
            $storedPath = StorageHelper::store($file, $directory, $filename);

            $fileData = [
                'original_name' => $originalName,
                'filename' => $filename,
                'path' => $storedPath,
                'type' => $type,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'url' => $this->disk()->url($storedPath),
            ];

            // Create resized versions for different use cases
            if ($request->boolean('resize', true) && $this->isLocalDisk()) {
                $resizedVersions = $this->createImageResizes($storedPath, $type);
                $fileData['resized_versions'] = $resizedVersions;
            }

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => $fileData,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'avatar' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048', // 2MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $file = $request->file('avatar');
            $user = auth()->user();

            // Delete old avatar from configured disk
            if ($user->avatar) {
                $this->disk()->delete($user->avatar);
            }

            // Generate unique filename
            $filename = 'avatar_'.time().'_'.Str::random(10).'.'.$file->getClientOriginalExtension();
            $storedPath = StorageHelper::store($file, 'avatars', $filename);

            // Create thumbnail versions (only for local storage)
            $thumbnails = $this->isLocalDisk() ? $this->createAvatarThumbnails($storedPath) : [];

            // Update user avatar
            $user->update(['avatar' => $storedPath]);

            return response()->json([
                'success' => true,
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'path' => $storedPath,
                    'url' => $this->disk()->url($storedPath),
                    'thumbnails' => $thumbnails,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload avatar',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function compressAudio(string $path, string $quality): string
    {
        $fullPath = $this->disk()->path($path);
        $pathInfo = pathinfo($path);
        $compressedFilename = $pathInfo['filename'].'_compressed.mp3';
        $compressedPath = $pathInfo['dirname'].'/'.$compressedFilename;
        $compressedFullPath = $this->disk()->path($compressedPath);

        // Set bitrate based on quality for African market data efficiency
        $bitrate = match ($quality) {
            'low' => 96,     // Very data-efficient
            'medium' => 128, // Good balance
            'high' => 192,   // Higher quality
            default => 128
        };

        try {
            $ffmpeg = \FFMpeg\FFMpeg::create();
            $audio = $ffmpeg->open($fullPath);

            $format = new \FFMpeg\Format\Audio\Mp3;
            $format->setAudioKiloBitrate($bitrate);

            $audio->save($format, $compressedFullPath);

            return $compressedPath;
        } catch (\Exception $e) {
            \Log::error('Audio compression failed: '.$e->getMessage());
            throw $e;
        }
    }

    private function extractAudioMetadata(string $path): array
    {
        $fullPath = $this->disk()->path($path);

        try {
            $ffprobe = \FFMpeg\FFProbe::create();
            $duration = $ffprobe->format($fullPath)->get('duration_seconds');

            return [
                'duration_seconds' => (int) $duration,
                'duration_formatted' => $this->formatDuration((int) $duration),
            ];
        } catch (\Throwable $e) {
            \Log::warning('Could not extract audio metadata: '.$e->getMessage());

            return [
                'duration_seconds' => 0,
                'duration_formatted' => '00:00',
            ];
        }
    }

    private function createImageResizes(string $path, string $type): array
    {
        $sizes = match ($type) {
            'cover', 'album' => [
                'thumbnail' => [150, 150],
                'small' => [300, 300],
                'medium' => [600, 600],
                'large' => [1200, 1200],
            ],
            'artist' => [
                'thumbnail' => [100, 100],
                'small' => [200, 200],
                'medium' => [400, 400],
            ],
            'playlist' => [
                'thumbnail' => [150, 150],
                'small' => [300, 300],
            ],
            default => [
                'thumbnail' => [150, 150],
                'small' => [300, 300],
            ]
        };

        $resized = [];
        $fullPath = $this->disk()->path($path);
        $pathInfo = pathinfo($path);

        foreach ($sizes as $sizeName => [$width, $height]) {
            try {
                $resizedFilename = $pathInfo['filename']."_{$sizeName}.".$pathInfo['extension'];
                $resizedPath = $pathInfo['dirname'].'/'.$resizedFilename;
                $resizedFullPath = $this->disk()->path($resizedPath);

                // Create resized image using GD or Imagick
                $this->resizeImage($fullPath, $resizedFullPath, $width, $height);

                $resized[$sizeName] = [
                    'path' => $resizedPath,
                    'url' => $this->disk()->url($resizedPath),
                    'width' => $width,
                    'height' => $height,
                ];
            } catch (\Exception $e) {
                \Log::warning("Failed to create {$sizeName} resize: ".$e->getMessage());
            }
        }

        return $resized;
    }

    private function createAvatarThumbnails(string $path): array
    {
        return $this->createImageResizes($path, 'avatar');
    }

    private function resizeImage(string $sourcePath, string $destPath, int $width, int $height): void
    {
        $imageInfo = getimagesize($sourcePath);
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];

        // Create source image
        $sourceImage = match ($sourceType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : throw new \Exception('WebP support is not available'),
            default => throw new \Exception('Unsupported image type')
        };

        // Create destination image
        $destImage = imagecreatetruecolor($width, $height);

        // Preserve transparency for PNG/WebP
        if (in_array($sourceType, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
            $transparent = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
            imagefilledrectangle($destImage, 0, 0, $width, $height, $transparent);
        }

        // Resize image
        imagecopyresampled($destImage, $sourceImage, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        // Save resized image
        match ($sourceType) {
            IMAGETYPE_JPEG => imagejpeg($destImage, $destPath, 85),
            IMAGETYPE_PNG => imagepng($destImage, $destPath),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($destImage, $destPath, 85) : throw new \Exception('WebP support is not available'),
            default => throw new \Exception('Unsupported image type')
        };

        // Clean up memory
        imagedestroy($sourceImage);
        imagedestroy($destImage);
    }

    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
