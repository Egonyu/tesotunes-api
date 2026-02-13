<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\MusicUpload;
use App\Models\Song;
use App\Models\ContentReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessAlbumBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes for large albums
    public $tries = 3;

    public function __construct(
        public Album $album,
        public string $batchId
    ) {}

    public function handle(): void
    {
        try {
            Log::info("Starting album batch processing for album: {$this->album->id}, batch: {$this->batchId}");

            $this->album->update(['batch_upload_status' => 'processing']);

            // Get all uploads for this batch
            $uploads = MusicUpload::inBatch($this->batchId)
                ->where('artist_id', $this->album->artist_id)
                ->get();

            if ($uploads->isEmpty()) {
                throw new \Exception("No uploads found for batch: {$this->batchId}");
            }

            // Check if all uploads are processed
            $processedUploads = $uploads->where('processing_status', 'processed');
            $failedUploads = $uploads->where('processing_status', 'failed');

            Log::info("Batch status - Total: {$uploads->count()}, Processed: {$processedUploads->count()}, Failed: {$failedUploads->count()}");

            // Update album progress
            $this->album->update([
                'tracks_uploaded' => $uploads->count(),
                'tracks_processed' => $processedUploads->count(),
            ]);

            // If not all uploads are processed, wait for them
            if ($processedUploads->count() < $uploads->count() - $failedUploads->count()) {
                Log::info("Not all uploads processed yet, retrying in 60 seconds");
                $this->release(60); // Retry in 1 minute
                return;
            }

            // Process only successfully uploaded tracks
            $validUploads = $processedUploads->filter(function ($upload) {
                return $upload->ready_for_distribution &&
                       $upload->audio_quality_score >= 70 &&
                       !$upload->hasAudioIssues();
            });

            if ($validUploads->isEmpty()) {
                throw new \Exception("No valid uploads found for album creation");
            }

            // Analyze album content for Uganda-specific features
            $albumAnalysis = $this->analyzeAlbumContent($validUploads);

            // Create album metadata
            $this->updateAlbumMetadata($albumAnalysis);

            // Create songs from uploads
            $createdSongs = $this->createSongsFromUploads($validUploads);

            // Generate UPC code
            $this->generateUPCCode();

            // Update album statistics
            $this->updateAlbumStatistics($createdSongs);

            // Create content review for album
            $this->createAlbumContentReview($albumAnalysis);

            // Mark album as completed
            $this->album->update([
                'batch_upload_status' => 'completed',
                'distribution_status' => 'pending_review',
            ]);

            Log::info("Album batch processing completed for album: {$this->album->id}");

        } catch (\Exception $e) {
            Log::error("Album batch processing failed for album {$this->album->id}: " . $e->getMessage());

            $this->album->update([
                'batch_upload_status' => 'failed',
            ]);

            throw $e;
        }
    }

    private function analyzeAlbumContent($uploads): array
    {
        $languages = [];
        $localContentCount = 0;
        $explicitContentCount = 0;
        $totalDuration = 0;
        $avgQualityScore = 0;
        $genres = [];

        foreach ($uploads as $upload) {
            // Collect languages
            if ($upload->detected_languages) {
                $languages = array_merge($languages, $upload->detected_languages);
            }

            // Check local content
            if (in_array('Luganda', $upload->detected_languages ?? []) ||
                in_array('Swahili', $upload->detected_languages ?? [])) {
                $localContentCount++;
            }

            // Check explicit content
            if ($upload->explicit_content_detected) {
                $explicitContentCount++;
            }

            // Accumulate stats
            $totalDuration += $upload->duration_seconds ?? 0;
            $avgQualityScore += $upload->audio_quality_score ?? 0;
        }

        $languages = array_unique($languages);
        $avgQualityScore = $avgQualityScore / $uploads->count();

        // Determine primary language
        $primaryLanguage = 'English';
        if (in_array('Luganda', $languages)) {
            $primaryLanguage = 'Luganda';
        } elseif (in_array('Swahili', $languages)) {
            $primaryLanguage = 'Swahili';
        }

        // Determine cultural theme
        $culturalTheme = null;
        if ($localContentCount > $uploads->count() * 0.7) {
            $culturalTheme = 'Traditional Ugandan';
        } elseif ($localContentCount > $uploads->count() * 0.3) {
            $culturalTheme = 'Afro-Fusion';
        }

        return [
            'total_tracks' => $uploads->count(),
            'total_duration' => $totalDuration,
            'avg_quality_score' => $avgQualityScore,
            'languages' => $languages,
            'primary_language' => $primaryLanguage,
            'local_content_percentage' => ($localContentCount / $uploads->count()) * 100,
            'explicit_content_percentage' => ($explicitContentCount / $uploads->count()) * 100,
            'contains_local_content' => $localContentCount > 0,
            'cultural_theme' => $culturalTheme,
            'requires_cultural_review' => $localContentCount > 0,
        ];
    }

    private function updateAlbumMetadata(array $analysis): void
    {
        $this->album->update([
            'primary_language' => $analysis['primary_language'],
            'languages_featured' => $analysis['languages'],
            'cultural_theme' => $analysis['cultural_theme'],
            'contains_local_content' => $analysis['contains_local_content'],
            'explicit_content' => $analysis['explicit_content_percentage'] > 50,
            'target_regions' => $this->determineTargetRegions($analysis),
        ]);
    }

    private function determineTargetRegions(array $analysis): array
    {
        $regions = ['Uganda']; // Always include Uganda

        if ($analysis['contains_local_content']) {
            $regions = array_merge($regions, ['Kenya', 'Tanzania', 'Rwanda']); // East Africa
        }

        if (in_array('English', $analysis['languages'])) {
            $regions[] = 'Global';
        }

        return array_unique($regions);
    }

    private function createSongsFromUploads($uploads): array
    {
        $createdSongs = [];
        $trackNumber = 1;

        DB::beginTransaction();
        try {
            foreach ($uploads as $upload) {
                $song = Song::create([
                    'artist_id' => $this->album->artist_id,
                    'album_id' => $this->album->id,
                    'title' => $upload->detected_title ?: "Track {$trackNumber}",
                    'slug' => \Illuminate\Support\Str::slug($upload->detected_title ?: "track-{$trackNumber}-" . $this->album->title),
                    'audio_file' => $upload->file_path,
                    'duration' => $upload->duration_seconds ?? 0,
                    'track_number' => $trackNumber,
                    'status' => 'published',
                    'release_date' => $this->album->release_date ?? now(),

                    // Enhanced metadata from upload
                    'original_filename' => $upload->original_filename,
                    'file_format' => $upload->audio_format,
                    'file_size_bytes' => $upload->file_size_bytes,
                    'bitrate' => $upload->bitrate,
                    'sample_rate' => $upload->sample_rate,
                    'audio_quality' => $this->mapQualityScore($upload->audio_quality_score),
                    'file_hash' => $upload->file_hash,

                    // Uganda-specific metadata
                    'primary_language' => $this->determineSongLanguage($upload->detected_languages),
                    'languages_sung' => $upload->detected_languages,
                    'contains_local_language' => in_array('Luganda', $upload->detected_languages ?? []) ||
                                                in_array('Swahili', $upload->detected_languages ?? []),

                    // Content flags
                    'has_explicit_lyrics' => $upload->explicit_content_detected,
                    'is_explicit' => $upload->explicit_content_detected,

                    // Distribution status
                    'distribution_status' => 'draft',
                ]);

                // Generate ISRC code
                $song->update(['isrc_code' => $song->generateISRCCode()]);

                // Link to music upload
                $upload->update(['song_id' => $song->id]);

                $createdSongs[] = $song;
                $trackNumber++;
            }

            DB::commit();
            return $createdSongs;

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    private function mapQualityScore(?float $score): string
    {
        if (!$score) return 'standard';

        return match(true) {
            $score >= 95 => 'master',
            $score >= 85 => 'hi_res',
            $score >= 75 => 'cd',
            default => 'standard'
        };
    }

    private function determineSongLanguage(?array $languages): string
    {
        if (!$languages) return 'English';

        if (in_array('Luganda', $languages)) return 'Luganda';
        if (in_array('Swahili', $languages)) return 'Swahili';

        return $languages[0] ?? 'English';
    }

    private function generateUPCCode(): void
    {
        if ($this->album->upc_code) return;

        // Generate UPC-A (12-digit) code for Uganda
        // Format: Country(3) + Company(4) + Product(4) + Check(1)
        $countryCode = '800'; // Uganda code (simplified)
        $companyCode = str_pad($this->album->artist_id, 4, '0', STR_PAD_LEFT);
        $productCode = str_pad($this->album->id, 4, '0', STR_PAD_LEFT);

        $upcWithoutCheck = $countryCode . $companyCode . $productCode;
        $checkDigit = $this->calculateUPCCheckDigit($upcWithoutCheck);

        $this->album->update(['upc_code' => $upcWithoutCheck . $checkDigit]);
    }

    private function calculateUPCCheckDigit(string $code): int
    {
        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $digit = (int) $code[$i];
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }
        return (10 - ($sum % 10)) % 10;
    }

    private function updateAlbumStatistics(array $songs): void
    {
        $totalDuration = array_sum(array_column($songs, 'duration'));
        $totalSize = 0;

        foreach ($songs as $song) {
            $totalSize += $song->file_size_bytes ?? 0;
        }

        $this->album->update([
            'total_tracks_expected' => count($songs),
            'tracks_uploaded' => count($songs),
            'tracks_processed' => count($songs),
        ]);
    }

    private function createAlbumContentReview(array $analysis): void
    {
        $flags = [];
        $violations = [];

        // Check for content that needs review
        if ($analysis['explicit_content_percentage'] > 0) {
            $flags[] = 'explicit_content';
        }

        if ($analysis['requires_cultural_review']) {
            $flags[] = 'cultural_sensitivity';
        }

        if ($analysis['avg_quality_score'] < 75) {
            $flags[] = 'audio_quality_concerns';
        }

        // Determine priority
        $priority = 'medium';
        if (!empty($violations)) {
            $priority = 'urgent';
        } elseif ($analysis['contains_local_content']) {
            $priority = 'medium'; // Local content gets standard priority
        } else {
            $priority = 'low';
        }

        ContentReview::create([
            'reviewable_type' => Album::class,
            'reviewable_id' => $this->album->id,
            'content_type' => 'music',
            'review_type' => 'automated',
            'priority' => $priority,
            'automated_flags' => $flags,
            'confidence_score' => 85,
            'policy_violations' => $violations,
            'automated_reason' => 'Album created from batch upload, automated analysis completed',
            'contains_local_content' => $analysis['contains_local_content'],
            'detected_languages' => $analysis['languages'],
            'requires_cultural_review' => $analysis['requires_cultural_review'],
            'submitted_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessAlbumBatch job failed for album {$this->album->id}", [
            'batch_id' => $this->batchId,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->album->update([
            'batch_upload_status' => 'failed',
        ]);
    }
}