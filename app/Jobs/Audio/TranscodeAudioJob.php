<?php

namespace App\Jobs\Audio;

use App\Models\Song;
use App\Services\Audio\FFmpegService;
use App\Services\MusicStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscodeAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600; // 10 minutes
    public $backoff = [60, 120, 300]; // Retry delays in seconds

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Song $song,
        public string $quality // '320kbps' or '128kbps'
    ) {
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle(FFmpegService $ffmpeg, MusicStorageService $storage): void
    {
        try {
            Log::info("Starting audio transcoding", [
                'song_id' => $this->song->id,
                'quality' => $this->quality
            ]);

            // Get original file path
            $originalPath = $storage->getSongPath($this->song, 'original');

            if (!Storage::disk('local')->exists($originalPath)) {
                throw new \Exception("Original audio file not found: {$originalPath}");
            }

            // Prepare output path
            $qualityDir = $this->quality;
            $outputPath = $storage->getSongPath($this->song, $qualityDir);

            // Ensure directory exists
            $outputDir = dirname(Storage::disk('local')->path($outputPath));
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Determine bitrate
            $bitrate = $this->quality === '320kbps' ? '320k' : '128k';

            // Transcode audio
            $success = $ffmpeg->transcode(
                Storage::disk('local')->path($originalPath),
                Storage::disk('local')->path($outputPath),
                $bitrate,
                44100 // Sample rate
            );

            if (!$success) {
                throw new \Exception("Transcoding failed for quality: {$this->quality}");
            }

            // Upload to DigitalOcean Spaces if configured
            if (config('filesystems.disks.digitalocean')) {
                $doPath = "songs/{$this->song->user_id}/{$this->song->id}/{$qualityDir}/" . basename($outputPath);

                Storage::disk('digitalocean')->put(
                    $doPath,
                    Storage::disk('local')->get($outputPath)
                );

                Log::info("Uploaded transcoded audio to DigitalOcean", [
                    'song_id' => $this->song->id,
                    'quality' => $this->quality,
                    'path' => $doPath
                ]);
            }

            // Update song metadata
            $this->updateSongMetadata();

            Log::info("Audio transcoding completed", [
                'song_id' => $this->song->id,
                'quality' => $this->quality
            ]);

        } catch (\Exception $e) {
            Log::error("Audio transcoding failed", [
                'song_id' => $this->song->id,
                'quality' => $this->quality,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Update song metadata after transcoding
     */
    private function updateSongMetadata(): void
    {
        $processingStatus = $this->song->processing_status ?? [];
        $processingStatus[$this->quality] = 'completed';

        $this->song->update([
            'processing_status' => $processingStatus
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Transcoding job permanently failed", [
            'song_id' => $this->song->id,
            'quality' => $this->quality,
            'error' => $exception->getMessage()
        ]);

        // Update processing status to failed
        $processingStatus = $this->song->processing_status ?? [];
        $processingStatus[$this->quality] = 'failed';

        $this->song->update([
            'processing_status' => $processingStatus
        ]);
    }
}
