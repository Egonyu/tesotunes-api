<?php

namespace App\Services\Music;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Audio Processing Service
 * 
 * Handles audio file processing using FFmpeg:
 * - Metadata extraction
 * - Duration calculation
 * - Quality analysis
 * - Format detection
 */
class AudioProcessingService
{
    /**
     * Extract comprehensive metadata from audio file
     */
    public function extractMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception('Audio file not found: ' . $filePath);
        }

        try {
            // Use FFprobe to extract metadata
            $command = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams "%s" 2>&1',
                $filePath
            );

            $output = shell_exec($command);
            $data = json_decode($output, true);

            if (!$data || !isset($data['format'])) {
                throw new Exception('Failed to extract audio metadata');
            }

            $format = $data['format'];
            $audioStream = $this->getAudioStream($data['streams'] ?? []);

            return [
                'duration' => (int) round($format['duration'] ?? 0),
                'bitrate' => (int) ($format['bit_rate'] ?? 0),
                'format' => $format['format_name'] ?? 'unknown',
                'codec' => $audioStream['codec_name'] ?? 'unknown',
                'sample_rate' => (int) ($audioStream['sample_rate'] ?? 0),
                'channels' => (int) ($audioStream['channels'] ?? 0),
                'size' => (int) ($format['size'] ?? 0),
                'title' => $format['tags']['title'] ?? null,
                'artist' => $format['tags']['artist'] ?? null,
                'album' => $format['tags']['album'] ?? null,
                'year' => $format['tags']['date'] ?? null,
                'genre' => $format['tags']['genre'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error('Audio metadata extraction failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            // Return minimal metadata on error
            return [
                'duration' => 0,
                'bitrate' => 0,
                'format' => 'unknown',
                'codec' => 'unknown',
                'sample_rate' => 0,
                'channels' => 0,
                'size' => filesize($filePath),
            ];
        }
    }

    /**
     * Get audio duration in seconds
     */
    public function getDuration(string $filePath): int
    {
        try {
            $command = sprintf(
                'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "%s" 2>&1',
                $filePath
            );

            $output = trim(shell_exec($command));
            return (int) round(floatval($output));

        } catch (Exception $e) {
            Log::error('Failed to get audio duration', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Analyze audio quality
     */
    public function analyzeQuality(string $filePath): array
    {
        $metadata = $this->extractMetadata($filePath);

        $quality = 'unknown';
        $score = 0;

        // Determine quality based on bitrate
        $bitrate = $metadata['bitrate'] / 1000; // Convert to kbps

        if ($bitrate >= 320) {
            $quality = 'premium';
            $score = 100;
        } elseif ($bitrate >= 256) {
            $quality = 'high';
            $score = 85;
        } elseif ($bitrate >= 192) {
            $quality = 'good';
            $score = 70;
        } elseif ($bitrate >= 128) {
            $quality = 'standard';
            $score = 60;
        } else {
            $quality = 'low';
            $score = 40;
        }

        return [
            'quality_level' => $quality,
            'quality_score' => $score,
            'bitrate_kbps' => round($bitrate),
            'is_lossless' => in_array($metadata['format'], ['flac', 'wav', 'alac']),
            'recommendations' => $this->getQualityRecommendations($quality, $metadata),
        ];
    }

    /**
     * Detect audio format
     */
    public function detectFormat(string $filePath): string
    {
        $metadata = $this->extractMetadata($filePath);
        return $metadata['format'];
    }

    /**
     * Check if file is valid audio
     */
    public function isValidAudio(string $filePath): bool
    {
        try {
            $metadata = $this->extractMetadata($filePath);
            return $metadata['duration'] > 0 && $metadata['codec'] !== 'unknown';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Extract audio stream from streams array
     */
    protected function getAudioStream(array $streams): ?array
    {
        foreach ($streams as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                return $stream;
            }
        }
        return null;
    }

    /**
     * Get quality improvement recommendations
     */
    protected function getQualityRecommendations(string $quality, array $metadata): array
    {
        $recommendations = [];

        if ($quality === 'low') {
            $recommendations[] = 'Consider uploading a higher quality version (at least 192kbps)';
        }

        if ($metadata['sample_rate'] < 44100) {
            $recommendations[] = 'Sample rate below 44.1kHz may affect audio quality';
        }

        if ($metadata['channels'] < 2) {
            $recommendations[] = 'Mono audio detected. Stereo recommended for better listening experience';
        }

        return $recommendations;
    }

    /**
     * Validate audio meets platform requirements
     */
    public function validatePlatformRequirements(string $filePath): array
    {
        $metadata = $this->extractMetadata($filePath);
        $errors = [];

        // Minimum duration: 30 seconds
        if ($metadata['duration'] < 30) {
            $errors[] = 'Audio must be at least 30 seconds long';
        }

        // Maximum duration: 10 minutes (600 seconds) for free tier
        if ($metadata['duration'] > 600) {
            $errors[] = 'Audio exceeds maximum duration of 10 minutes for free tier';
        }

        // Minimum bitrate: 128kbps
        if ($metadata['bitrate'] > 0 && $metadata['bitrate'] < 128000) {
            $errors[] = 'Audio bitrate below minimum 128kbps';
        }

        // Sample rate: 44.1kHz or 48kHz recommended
        if ($metadata['sample_rate'] > 0 && $metadata['sample_rate'] < 44100) {
            $errors[] = 'Sample rate below recommended 44.1kHz';
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'metadata' => $metadata,
        ];
    }
}
