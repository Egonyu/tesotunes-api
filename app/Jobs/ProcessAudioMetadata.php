<?php

namespace App\Jobs;

use App\Models\MusicUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProcessAudioMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    public function __construct(
        public MusicUpload $upload
    ) {}

    public function handle(): void
    {
        try {
            Log::info("Starting metadata extraction for upload: {$this->upload->id}");

            $this->upload->update(['processing_status' => 'processing']);

            // Get file path
            $filePath = Storage::disk('private')->path($this->upload->file_path);

            if (!file_exists($filePath)) {
                throw new \Exception("Audio file not found: {$filePath}");
            }

            // Extract metadata using getID3 library
            $metadata = $this->extractAudioMetadata($filePath);

            // Analyze audio quality
            $qualityAnalysis = $this->analyzeAudioQuality($metadata);

            // Detect content characteristics
            $contentAnalysis = $this->analyzeContent($metadata);

            // Update upload with extracted data
            $this->upload->update([
                'processing_status' => 'processed',
                'processing_progress' => 100,
                'duration_seconds' => $metadata['duration'] ?? null,
                'bitrate' => $metadata['bitrate'] ?? null,
                'sample_rate' => $metadata['sample_rate'] ?? null,
                'channels' => $metadata['channels'] ?? null,
                'is_stereo' => ($metadata['channels'] ?? 1) > 1,
                'audio_format' => $metadata['format'] ?? strtoupper($this->upload->file_extension),
                'audio_quality_score' => $qualityAnalysis['score'],
                'audio_issues' => $qualityAnalysis['issues'],
                'detected_title' => $metadata['title'] ?? null,
                'detected_artist' => $metadata['artist'] ?? null,
                'detected_album' => $metadata['album'] ?? null,
                'detected_genre' => $metadata['genre'] ?? null,
                'detected_year' => $metadata['year'] ?? null,
                'detected_track_number' => $metadata['track_number'] ?? null,
                'detected_metadata' => $metadata,
                'contains_vocals' => $contentAnalysis['has_vocals'],
                'vocal_percentage' => $contentAnalysis['vocal_percentage'],
                'detected_languages' => $contentAnalysis['languages'],
                'explicit_content_detected' => $contentAnalysis['explicit_content'],
                'ready_for_distribution' => $qualityAnalysis['score'] >= 70,
                'processing_results' => [
                    'metadata' => $metadata,
                    'quality' => $qualityAnalysis,
                    'content' => $contentAnalysis,
                    'processed_at' => now()->toISOString(),
                ]
            ]);

            Log::info("Metadata extraction completed for upload: {$this->upload->id}");

        } catch (\Exception $e) {
            Log::error("Metadata extraction failed for upload {$this->upload->id}: " . $e->getMessage());

            $this->upload->update([
                'processing_status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function extractAudioMetadata(string $filePath): array
    {
        // Simulate getID3 library functionality
        // In a real implementation, you would use getID3 or similar library
        $fileInfo = pathinfo($filePath);
        $fileSize = filesize($filePath);

        // Basic file analysis
        $metadata = [
            'filename' => $fileInfo['basename'],
            'filesize' => $fileSize,
            'encoding' => strtoupper($fileInfo['extension'] ?? 'unknown'),
        ];

        // Simulate reading ID3 tags and audio properties
        // This would be replaced with actual getID3 implementation
        $metadata = array_merge($metadata, $this->simulateGetID3Analysis($filePath));

        return $metadata;
    }

    private function simulateGetID3Analysis(string $filePath): array
    {
        // This simulates what getID3 would return
        // In production, replace with actual getID3 implementation

        $fileSize = filesize($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Estimate duration based on file size and format
        $estimatedDuration = $this->estimateDuration($fileSize, $extension);

        // Generate realistic metadata
        return [
            'duration' => $estimatedDuration,
            'bitrate' => $this->estimateBitrate($fileSize, $estimatedDuration),
            'sample_rate' => $this->getDefaultSampleRate($extension),
            'channels' => 2,
            'format' => strtoupper($extension),
            'title' => $this->extractTitleFromFilename(basename($filePath)),
            'artist' => null,
            'album' => null,
            'genre' => null,
            'year' => null,
            'track_number' => null,
            'comment' => null,
        ];
    }

    private function estimateDuration(int $fileSize, string $extension): int
    {
        // Rough estimation based on file size and format
        $bytesPerSecond = match($extension) {
            'mp3' => 16000,  // ~128kbps MP3
            'wav' => 176400, // CD quality WAV
            'flac' => 100000, // Compressed lossless
            'aac', 'm4a' => 12000, // ~96kbps AAC
            default => 16000
        };

        return max(30, min(600, intval($fileSize / $bytesPerSecond))); // 30s to 10min range
    }

    private function estimateBitrate(int $fileSize, int $duration): int
    {
        if ($duration === 0) return 128;

        $bitsPerSecond = ($fileSize * 8) / $duration;
        return max(64, min(320, intval($bitsPerSecond / 1000))); // 64-320 kbps range
    }

    private function getDefaultSampleRate(string $extension): int
    {
        return match($extension) {
            'wav', 'flac' => 44100,
            'mp3', 'aac', 'm4a' => 44100,
            default => 44100
        };
    }

    private function extractTitleFromFilename(string $filename): string
    {
        // Remove extension
        $title = pathinfo($filename, PATHINFO_FILENAME);

        // Clean up common patterns
        $title = preg_replace('/^\d+[\.\-\s]*/', '', $title); // Remove track numbers
        $title = preg_replace('/[\-_]/', ' ', $title); // Replace dashes/underscores with spaces
        $title = preg_replace('/\s+/', ' ', $title); // Multiple spaces to single

        return trim(ucwords(strtolower($title)));
    }

    private function analyzeAudioQuality(array $metadata): array
    {
        $score = 100;
        $issues = [];

        // Check bitrate
        $bitrate = $metadata['bitrate'] ?? 0;
        if ($bitrate < 128) {
            $score -= 30;
            $issues[] = 'low_bitrate';
        } elseif ($bitrate < 192) {
            $score -= 15;
        }

        // Check sample rate
        $sampleRate = $metadata['sample_rate'] ?? 0;
        if ($sampleRate < 44100) {
            $score -= 20;
            $issues[] = 'low_sample_rate';
        }

        // Check file format
        $format = strtolower($metadata['format'] ?? '');
        if (in_array($format, ['wav', 'flac'])) {
            $score += 5; // Bonus for lossless
        } elseif ($format === 'mp3' && $bitrate >= 192) {
            // Good quality MP3
        } else {
            $score -= 10;
        }

        // Simulate additional quality checks
        $duration = $metadata['duration'] ?? 0;
        if ($duration < 30) {
            $score -= 20;
            $issues[] = 'too_short';
        } elseif ($duration > 600) {
            $score -= 10;
            $issues[] = 'very_long';
        }

        // Random quality issues simulation
        if (rand(1, 10) === 1) {
            $score -= 25;
            $issues[] = 'clipping';
        }

        if (rand(1, 15) === 1) {
            $score -= 15;
            $issues[] = 'noise';
        }

        return [
            'score' => max(0, min(100, $score)),
            'issues' => $issues,
        ];
    }

    private function analyzeContent(array $metadata): array
    {
        // Simulate content analysis
        $hasVocals = rand(1, 10) > 2; // 80% chance of having vocals
        $vocalPercentage = $hasVocals ? rand(40, 90) : rand(0, 20);

        // Language detection based on filename and artist context
        $languages = $this->detectLanguages($metadata);

        // Explicit content detection (simplified)
        $explicitContent = $this->detectExplicitContent($metadata);

        return [
            'has_vocals' => $hasVocals,
            'vocal_percentage' => $vocalPercentage,
            'languages' => $languages,
            'explicit_content' => $explicitContent,
        ];
    }

    private function detectLanguages(array $metadata): array
    {
        $title = strtolower($metadata['title'] ?? '');
        $artist = strtolower($metadata['artist'] ?? '');
        $filename = strtolower($metadata['filename'] ?? '');

        $languages = ['English']; // Default

        // Simple keyword-based language detection
        $lugandaKeywords = ['nga', 'oli', 'kati', 'munange', 'webale', 'simanyi'];
        $swahiliKeywords = ['sana', 'habari', 'mambo', 'rafiki', 'asante'];

        $allText = $title . ' ' . $artist . ' ' . $filename;

        foreach ($lugandaKeywords as $keyword) {
            if (strpos($allText, $keyword) !== false) {
                $languages[] = 'Luganda';
                break;
            }
        }

        foreach ($swahiliKeywords as $keyword) {
            if (strpos($allText, $keyword) !== false) {
                $languages[] = 'Swahili';
                break;
            }
        }

        return array_unique($languages);
    }

    private function detectExplicitContent(array $metadata): bool
    {
        // Simple explicit content detection
        $title = strtolower($metadata['title'] ?? '');
        $explicitKeywords = ['explicit', 'parental', 'advisory', 'uncensored'];

        foreach ($explicitKeywords as $keyword) {
            if (strpos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessAudioMetadata job failed for upload {$this->upload->id}", [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->upload->update([
            'processing_status' => 'failed',
            'processing_error' => $exception->getMessage(),
        ]);
    }
}