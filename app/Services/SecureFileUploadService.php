<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecureFileUploadService
{
    // Allowed audio file types with strict MIME type checking
    const ALLOWED_AUDIO_TYPES = [
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/wave', 'audio/x-wav'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'aac' => ['audio/aac', 'audio/x-aac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'ogg' => ['audio/ogg', 'application/ogg'],
    ];

    // Maximum file sizes in bytes
    const MAX_FILE_SIZES = [
        'mp3' => 50 * 1024 * 1024,  // 50MB
        'wav' => 100 * 1024 * 1024, // 100MB
        'flac' => 80 * 1024 * 1024, // 80MB
        'aac' => 40 * 1024 * 1024,  // 40MB
        'm4a' => 40 * 1024 * 1024,  // 40MB
        'ogg' => 50 * 1024 * 1024,  // 50MB
    ];

    // Audio quality constraints
    const MIN_BITRATE = 96;   // kbps
    const MAX_BITRATE = 320;  // kbps
    const MIN_DURATION = 30;  // seconds
    const MAX_DURATION = 900; // 15 minutes

    /**
     * Validate uploaded audio file securely
     */
    public function validateAudioFile(UploadedFile $file): array
    {
        $validation = [
            'valid' => false,
            'errors' => [],
            'metadata' => []
        ];

        try {
            // Basic file validation
            if (!$file->isValid()) {
                $validation['errors'][] = 'File upload failed or corrupted';
                return $validation;
            }

            // Check file size
            if ($file->getSize() > max(self::MAX_FILE_SIZES)) {
                $validation['errors'][] = 'File size exceeds maximum allowed size';
                return $validation;
            }

            // Validate MIME type and extension
            $mimeType = $file->getMimeType();
            $extension = strtolower($file->getClientOriginalExtension());

            if (!$this->validateMimeType($mimeType, $extension)) {
                $validation['errors'][] = 'Invalid file type or suspicious file';
                return $validation;
            }

            // Check file size for specific format
            if (isset(self::MAX_FILE_SIZES[$extension]) &&
                $file->getSize() > self::MAX_FILE_SIZES[$extension]) {
                $validation['errors'][] = "File size exceeds maximum for {$extension} format";
                return $validation;
            }

            // Scan file content for malicious patterns
            if (!$this->scanFileContent($file)) {
                $validation['errors'][] = 'File contains suspicious content';
                return $validation;
            }

            // Extract and validate audio metadata
            $metadata = $this->extractAudioMetadata($file);
            if (!empty($metadata['errors'])) {
                $validation['errors'] = array_merge($validation['errors'], $metadata['errors']);
                return $validation;
            }

            $validation['valid'] = true;
            $validation['metadata'] = $metadata;

            Log::info('File upload validation successful', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $mimeType,
                'extension' => $extension
            ]);

        } catch (\Exception $e) {
            Log::error('File validation error', [
                'error' => $e->getMessage(),
                'filename' => $file->getClientOriginalName()
            ]);
            $validation['errors'][] = 'File validation failed';
        }

        return $validation;
    }

    /**
     * Validate MIME type against allowed types
     */
    private function validateMimeType(string $mimeType, string $extension): bool
    {
        if (!isset(self::ALLOWED_AUDIO_TYPES[$extension])) {
            return false;
        }

        return in_array($mimeType, self::ALLOWED_AUDIO_TYPES[$extension]);
    }

    /**
     * Scan file content for malicious patterns
     */
    private function scanFileContent(UploadedFile $file): bool
    {
        try {
            // Read first 1KB for basic content scanning
            $handle = fopen($file->getPathname(), 'rb');
            if (!$handle) {
                return false;
            }

            $header = fread($handle, 1024);
            fclose($handle);

            // Check for suspicious patterns
            $suspiciousPatterns = [
                '<?php',
                '<script',
                'javascript:',
                'eval(',
                'exec(',
                'system(',
                '<!DOCTYPE',
                '<html'
            ];

            foreach ($suspiciousPatterns as $pattern) {
                if (stripos($header, $pattern) !== false) {
                    Log::warning('Suspicious pattern detected in upload', [
                        'pattern' => $pattern,
                        'filename' => $file->getClientOriginalName()
                    ]);
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Content scanning failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Extract and validate audio metadata
     */
    private function extractAudioMetadata(UploadedFile $file): array
    {
        $metadata = [
            'duration' => null,
            'bitrate' => null,
            'sample_rate' => null,
            'format' => null,
            'errors' => []
        ];

        try {
            // Use getID3 or similar library if available
            // For now, we'll do basic validation
            $extension = strtolower($file->getClientOriginalExtension());
            $metadata['format'] = $extension;

            // Basic duration estimation (rough)
            $fileSize = $file->getSize();
            $estimatedBitrate = 128; // Assume 128kbps average
            $estimatedDuration = ($fileSize * 8) / ($estimatedBitrate * 1000);

            if ($estimatedDuration < self::MIN_DURATION) {
                $metadata['errors'][] = 'Audio file too short (minimum 30 seconds)';
            }

            if ($estimatedDuration > self::MAX_DURATION) {
                $metadata['errors'][] = 'Audio file too long (maximum 15 minutes)';
            }

            $metadata['duration'] = $estimatedDuration;
            $metadata['bitrate'] = $estimatedBitrate;

        } catch (\Exception $e) {
            Log::error('Metadata extraction failed', ['error' => $e->getMessage()]);
            $metadata['errors'][] = 'Could not extract audio metadata';
        }

        return $metadata;
    }

    /**
     * Securely store uploaded file
     */
    public function secureStore(UploadedFile $file, string $directory = 'uploads/music'): array
    {
        try {
            // Generate secure filename
            $secureFilename = $this->generateSecureFilename($file);

            // Store file in private storage
            $path = $file->storeAs($directory, $secureFilename, 'private');

            if (!$path) {
                throw new \Exception('File storage failed');
            }

            // Set appropriate permissions
            $fullPath = Storage::disk('private')->path($path);
            if (file_exists($fullPath)) {
                chmod($fullPath, 0644);
            }

            Log::info('File stored securely', [
                'original_name' => $file->getClientOriginalName(),
                'secure_name' => $secureFilename,
                'path' => $path
            ]);

            return [
                'success' => true,
                'path' => $path,
                'filename' => $secureFilename,
                'size' => $file->getSize()
            ];

        } catch (\Exception $e) {
            Log::error('Secure file storage failed', [
                'error' => $e->getMessage(),
                'filename' => $file->getClientOriginalName()
            ]);

            return [
                'success' => false,
                'error' => 'File storage failed'
            ];
        }
    }

    /**
     * Generate secure filename
     */
    private function generateSecureFilename(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $timestamp = now()->format('Y-m-d_H-i-s');
        $randomString = Str::random(16);

        return "audio_{$timestamp}_{$randomString}.{$extension}";
    }

    /**
     * Cleanup temporary files
     */
    public function cleanup(string $path): bool
    {
        try {
            if (Storage::disk('private')->exists($path)) {
                Storage::disk('private')->delete($path);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('File cleanup failed', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return false;
    }
}