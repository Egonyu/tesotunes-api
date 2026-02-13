<?php

namespace App\Services\Music;

use App\Models\Song;
use App\Models\Artist;
use App\Models\User;
use App\Models\MusicUpload;
use App\Services\MusicStorageService;
use App\Services\Music\ISRCService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

/**
 * Song Upload Service
 * 
 * Handles the complete song upload workflow including:
 * - File validation and storage
 * - Audio processing and transcoding
 * - Metadata extraction
 * - ISRC generation
 * - Database record creation
 */
class SongUploadService
{
    protected MusicStorageService $storageService;
    protected ISRCService $isrcService;
    protected AudioProcessingService $audioProcessor;

    public function __construct(
        MusicStorageService $storageService,
        ISRCService $isrcService,
        AudioProcessingService $audioProcessor
    ) {
        $this->storageService = $storageService;
        $this->isrcService = $isrcService;
        $this->audioProcessor = $audioProcessor;
    }

    /**
     * Process complete song upload
     * 
     * @param UploadedFile $file Audio file
     * @param array $metadata Song metadata
     * @param User $user Uploading user
     * @return Song Created song model
     * @throws Exception
     */
    public function processUpload(UploadedFile $file, array $metadata, User $user): Song
    {
        // Validate user can upload
        $this->validateUserCanUpload($user);

        // Validate file
        $this->validateAudioFile($file);

        // Get or create artist
        $artist = $this->getOrCreateArtist($user);

        DB::beginTransaction();

        try {
            // Step 1: Store audio file
            $audioResult = $this->storeAudioFile($file, $artist);

            // Step 2: Extract audio metadata
            $audioMetadata = $this->extractAudioMetadata($audioResult['file_path']);

            // Step 3: Create song record
            $song = $this->createSong($metadata, $artist, $user, $audioResult, $audioMetadata);

            // Step 4: Process artwork if provided
            if (isset($metadata['artwork'])) {
                $this->processArtwork($song, $metadata['artwork'], $artist);
            }

            // Step 5: Generate ISRC code
            $this->generateISRC($song);

            // Step 6: Queue background jobs for transcoding
            $this->queueBackgroundProcessing($song);

            DB::commit();

            \Log::info('Song uploaded successfully', [
                'song_id' => $song->id,
                'artist_id' => $artist->id,
                'user_id' => $user->id,
            ]);

            return $song;

        } catch (Exception $e) {
            DB::rollBack();
            
            // Cleanup uploaded files on error
            if (isset($audioResult)) {
                $this->storageService->deleteFile($audioResult['storage_path']);
            }

            \Log::error('Song upload failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Song upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate user has permission to upload
     */
    protected function validateUserCanUpload(User $user): void
    {
        if (!$user->hasRole(['artist', 'admin', 'super_admin'])) {
            throw new Exception('You must be an artist to upload music');
        }

        // Check if user has reached upload limit (if applicable)
        if (!$user->hasActiveSubscription()) {
            $uploadCount = Song::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            $dailyLimit = config('music.upload_limits.free_user_daily', 5);

            if ($uploadCount >= $dailyLimit) {
                throw new Exception("Daily upload limit reached ({$dailyLimit}). Upgrade to premium for unlimited uploads.");
            }
        }
    }

    /**
     * Validate audio file meets requirements
     */
    protected function validateAudioFile(UploadedFile $file): void
    {
        $allowedMimes = config('music.storage.allowed_mime_types.audio', [
            'audio/mpeg',
            'audio/wav',
            'audio/flac',
            'audio/aac',
        ]);

        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new Exception('Invalid audio file format. Supported formats: MP3, WAV, FLAC, AAC');
        }

        $maxSize = config('music.storage.limits.max_audio_size', 52428800); // 50MB

        if ($file->getSize() > $maxSize) {
            $maxMB = round($maxSize / 1024 / 1024);
            throw new Exception("File too large. Maximum size: {$maxMB}MB");
        }

        // Validate minimum duration (30 seconds)
        $duration = $this->audioProcessor->getDuration($file->getRealPath());
        if ($duration < 30) {
            throw new Exception('Audio must be at least 30 seconds long');
        }
    }

    /**
     * Get or create artist profile for user
     */
    protected function getOrCreateArtist(User $user): Artist
    {
        $artist = $user->artist ?? Artist::where('user_id', $user->id)->first();

        if (!$artist) {
            throw new Exception('Please complete your artist profile before uploading music');
        }

        return $artist;
    }

    /**
     * Store audio file in appropriate storage
     */
    protected function storeAudioFile(UploadedFile $file, Artist $artist): array
    {
        $result = $this->storageService->storeMusicFile(
            $file,
            $artist,
            'song',
            MusicStorageService::ACCESS_PRIVATE
        );

        if (!$result['success']) {
            throw new Exception('Failed to store audio file: ' . $result['error']);
        }

        return $result;
    }

    /**
     * Extract metadata from audio file
     */
    protected function extractAudioMetadata(string $filePath): array
    {
        return $this->audioProcessor->extractMetadata($filePath);
    }

    /**
     * Create song database record
     */
    protected function createSong(
        array $metadata,
        Artist $artist,
        User $user,
        array $audioResult,
        array $audioMetadata
    ): Song {
        // Generate unique slug
        $slug = $this->generateUniqueSlug($metadata['title'], $artist);

        // Determine status based on publish type
        $status = match($metadata['publish_type'] ?? 'draft') {
            'now' => 'published',
            'schedule' => 'scheduled',
            default => 'draft',
        };

        return Song::create([
            // Identity
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'title' => $metadata['title'],
            'slug' => $slug,
            'description' => $metadata['description'] ?? null,

            // Audio files
            'audio_file_original' => $audioResult['storage_path'],
            'artwork' => null, // Set later if provided

            // File metadata
            'duration_seconds' => $audioMetadata['duration'] ?? 0,
            'file_format' => $audioMetadata['format'] ?? pathinfo($audioResult['storage_path'], PATHINFO_EXTENSION),
            'file_size_bytes' => $audioResult['file_info']['size'],
            'file_hash' => $audioResult['file_info']['hash'],
            'bitrate_original' => $audioMetadata['bitrate'] ?? null,
            'sample_rate' => $audioMetadata['sample_rate'] ?? null,

            // Classification
            'primary_genre_id' => $metadata['genre_id'] ?? null,

            // Content flags
            'is_explicit' => $metadata['is_explicit'] ?? false,
            'primary_language' => $metadata['primary_language'] ?? 'English',

            // Status & visibility
            'status' => $status,
            'visibility' => $metadata['visibility'] ?? 'public',
            'is_downloadable' => $metadata['is_downloadable'] ?? true,
            'is_streamable' => true,
            'allow_comments' => $metadata['allow_comments'] ?? true,

            // Pricing
            'price' => $metadata['price'] ?? 0,
            'is_free' => ($metadata['price'] ?? 0) == 0,
            'currency' => 'UGX',

            // Distribution
            'distribution_status' => 'not_submitted',

            // Credits
            'composer' => $metadata['composer'] ?? null,
            'producer' => $metadata['producer'] ?? null,
            'featured_artists' => $metadata['featured_artists'] ?? null,

            // Release info
            'release_date' => $metadata['release_date'] ?? now(),
            'recording_date' => $metadata['recording_date'] ?? null,
            'recording_studio' => $metadata['recording_studio'] ?? null,

            // Rights
            'master_ownership_percentage' => 100, // Full ownership by default
            'copyright_year' => now()->year,
            'copyright_holder' => $artist->name,
            'license_type' => 'all_rights_reserved',
        ]);
    }

    /**
     * Process and store artwork
     */
    protected function processArtwork(Song $song, UploadedFile $artworkFile, Artist $artist): void
    {
        $artworkResult = $this->storageService->storeArtwork(
            $artworkFile,
            $artist,
            'song_cover',
            MusicStorageService::ACCESS_PUBLIC
        );

        if ($artworkResult['success']) {
            $song->update([
                'artwork' => $artworkResult['storage_path'] ?? $artworkResult['storage']['path'] ?? null
            ]);
        } else {
            \Log::warning('Artwork upload failed for song', [
                'song_id' => $song->id,
                'error' => $artworkResult['error']
            ]);
        }
    }

    /**
     * Generate ISRC code for song
     */
    protected function generateISRC(Song $song): void
    {
        if (config('music.isrc.auto_generate', true)) {
            $isrcCode = $this->isrcService->generate($song);
            $song->update(['isrc_code' => $isrcCode]);

            \Log::info('ISRC generated', [
                'song_id' => $song->id,
                'isrc_code' => $isrcCode
            ]);
        }
    }

    /**
     * Queue background processing jobs
     */
    protected function queueBackgroundProcessing(Song $song): void
    {
        // High priority: Transcode to multiple bitrates
        \App\Jobs\TranscodeSongJob::dispatch($song, '320kbps')->onQueue('high');
        \App\Jobs\TranscodeSongJob::dispatch($song, '128kbps')->onQueue('high');
        
        // High priority: Generate preview clip (30 seconds)
        \App\Jobs\GeneratePreviewJob::dispatch($song)->onQueue('high');

        // Default priority: Generate waveform visualization
        \App\Jobs\GenerateWaveformJob::dispatch($song)->onQueue('default');

        // Low priority: Extract additional metadata
        \App\Jobs\ExtractAudioMetadataJob::dispatch($song)->onQueue('low');
    }

    /**
     * Generate unique slug for song
     */
    protected function generateUniqueSlug(string $title, Artist $artist): string
    {
        $baseSlug = Str::slug($title . '-' . $artist->name);
        $slug = $baseSlug;
        $counter = 1;

        while (Song::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Create upload record for tracking
     * 
     * @param array $data Upload data
     * @return \App\Models\MusicUpload
     */
    public function createUploadRecord(array $data): \App\Models\MusicUpload
    {
        return \App\Models\MusicUpload::create($data);
    }
}
