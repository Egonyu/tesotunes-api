<?php

namespace App\Http\Controllers\Api\Upload;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;

class FileController extends Controller
{
    public function uploadAudio(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'audio' => 'required|file|mimes:mp3,wav,flac,m4a,aac|max:51200', // 50MB max
                'compress' => 'boolean',
                'quality' => 'nullable|in:low,medium,high'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('audio');
            $user = auth()->user();

            // Generate unique filename
            $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $path = 'audio/' . $user->id . '/' . $filename;

            // Store original file
            $storedPath = $file->storeAs('audio/' . $user->id, $filename, 'public');

            $fileData = [
                'original_name' => $file->getClientOriginalName(),
                'filename' => $filename,
                'path' => $storedPath,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'url' => Storage::disk('public')->url($storedPath),
            ];

            // Create compressed version if requested (for African market data efficiency)
            if ($request->boolean('compress', true)) {
                try {
                    $compressedPath = $this->compressAudio($storedPath, $request->get('quality', 'medium'));
                    $fileData['compressed_path'] = $compressedPath;
                    $fileData['compressed_url'] = Storage::disk('public')->url($compressedPath);
                } catch (\Exception $e) {
                    // If compression fails, continue with original file
                    \Log::warning('Audio compression failed: ' . $e->getMessage());
                }
            }

            // Extract audio metadata
            $metadata = $this->extractAudioMetadata($storedPath);
            $fileData = array_merge($fileData, $metadata);

            return response()->json([
                'success' => true,
                'message' => 'Audio uploaded successfully',
                'data' => $fileData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload audio',
                'error' => $e->getMessage()
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
                'height' => 'nullable|integer|min:100|max:2000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('image');
            $user = auth()->user();
            $type = $request->type;

            // Generate unique filename
            $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $directory = "images/{$type}/" . $user->id;

            // Store original image
            $storedPath = $file->storeAs($directory, $filename, 'public');

            $fileData = [
                'original_name' => $file->getClientOriginalName(),
                'filename' => $filename,
                'path' => $storedPath,
                'type' => $type,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'url' => Storage::disk('public')->url($storedPath),
            ];

            // Create resized versions for different use cases
            if ($request->boolean('resize', true)) {
                $resizedVersions = $this->createImageResizes($storedPath, $type);
                $fileData['resized_versions'] = $resizedVersions;
            }

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => $fileData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'avatar' => 'required|image|mimes:jpeg,png,jpg|max:2048', // 2MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('avatar');
            $user = auth()->user();

            // Delete old avatar
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }

            // Generate unique filename
            $filename = 'avatar_' . time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $storedPath = $file->storeAs('avatars', $filename, 'public');

            // Create thumbnail versions
            $thumbnails = $this->createAvatarThumbnails($storedPath);

            // Update user avatar
            $user->update(['avatar' => $storedPath]);

            return response()->json([
                'success' => true,
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'path' => $storedPath,
                    'url' => Storage::disk('public')->url($storedPath),
                    'thumbnails' => $thumbnails
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload avatar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function compressAudio(string $path, string $quality): string
    {
        $fullPath = Storage::disk('public')->path($path);
        $pathInfo = pathinfo($path);
        $compressedFilename = $pathInfo['filename'] . '_compressed.mp3';
        $compressedPath = $pathInfo['dirname'] . '/' . $compressedFilename;
        $compressedFullPath = Storage::disk('public')->path($compressedPath);

        // Set bitrate based on quality for African market data efficiency
        $bitrate = match($quality) {
            'low' => 96,     // Very data-efficient
            'medium' => 128, // Good balance
            'high' => 192,   // Higher quality
            default => 128
        };

        try {
            $ffmpeg = FFMpeg::create();
            $audio = $ffmpeg->open($fullPath);

            $format = new Mp3();
            $format->setAudioKiloBitrate($bitrate);

            $audio->save($format, $compressedFullPath);

            return $compressedPath;
        } catch (\Exception $e) {
            \Log::error('Audio compression failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function extractAudioMetadata(string $path): array
    {
        $fullPath = Storage::disk('public')->path($path);

        try {
            $ffprobe = \FFMpeg\FFProbe::create();
            $duration = $ffprobe->format($fullPath)->get('duration_seconds');

            return [
                'duration_seconds' => (int) $duration,
                'duration_formatted' => $this->formatDuration((int) $duration)
            ];
        } catch (\Exception $e) {
            \Log::warning('Could not extract audio metadata: ' . $e->getMessage());
            return [
                'duration_seconds' => 0,
                'duration_formatted' => '00:00'
            ];
        }
    }

    private function createImageResizes(string $path, string $type): array
    {
        $sizes = match($type) {
            'cover', 'album' => [
                'thumbnail' => [150, 150],
                'small' => [300, 300],
                'medium' => [600, 600],
                'large' => [1200, 1200]
            ],
            'artist' => [
                'thumbnail' => [100, 100],
                'small' => [200, 200],
                'medium' => [400, 400]
            ],
            'playlist' => [
                'thumbnail' => [150, 150],
                'small' => [300, 300]
            ],
            default => [
                'thumbnail' => [150, 150],
                'small' => [300, 300]
            ]
        };

        $resized = [];
        $fullPath = Storage::disk('public')->path($path);
        $pathInfo = pathinfo($path);

        foreach ($sizes as $sizeName => [$width, $height]) {
            try {
                $resizedFilename = $pathInfo['filename'] . "_{$sizeName}." . $pathInfo['extension'];
                $resizedPath = $pathInfo['dirname'] . '/' . $resizedFilename;
                $resizedFullPath = Storage::disk('public')->path($resizedPath);

                // Create resized image using GD or Imagick
                $this->resizeImage($fullPath, $resizedFullPath, $width, $height);

                $resized[$sizeName] = [
                    'path' => $resizedPath,
                    'url' => Storage::disk('public')->url($resizedPath),
                    'width' => $width,
                    'height' => $height
                ];
            } catch (\Exception $e) {
                \Log::warning("Failed to create {$sizeName} resize: " . $e->getMessage());
            }
        }

        return $resized;
    }

    private function createAvatarThumbnails(string $path): array
    {
        $sizes = [
            'small' => [50, 50],
            'medium' => [100, 100],
            'large' => [200, 200]
        ];

        return $this->createImageResizes($path, 'avatar');
    }

    private function resizeImage(string $sourcePath, string $destPath, int $width, int $height): void
    {
        $imageInfo = getimagesize($sourcePath);
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];

        // Create source image
        $sourceImage = match($sourceType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            default => throw new \Exception('Unsupported image type')
        };

        // Create destination image
        $destImage = imagecreatetruecolor($width, $height);

        // Preserve transparency for PNG
        if ($sourceType === IMAGETYPE_PNG) {
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
            $transparent = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
            imagefilledrectangle($destImage, 0, 0, $width, $height, $transparent);
        }

        // Resize image
        imagecopyresampled($destImage, $sourceImage, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        // Save resized image
        match($sourceType) {
            IMAGETYPE_JPEG => imagejpeg($destImage, $destPath, 85),
            IMAGETYPE_PNG => imagepng($destImage, $destPath),
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