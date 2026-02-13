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

class GeneratePreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300; // 5 minutes
    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Song $song,
        public int $startTime = 0,
        public int $duration = 30
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(FFmpegService $ffmpeg, MusicStorageService $storage): void
    {
        try {
            Log::info("Starting preview generation", [
                'song_id' => $this->song->id,
                'duration' => $this->duration
            ]);

            // Get original file path
            $originalPath = $storage->getSongPath($this->song, 'original');

            if (!Storage::disk('local')->exists($originalPath)) {
                throw new \Exception("Original audio file not found: {$originalPath}");
            }

            // Prepare output path
            $previewPath = $storage->getSongPath($this->song, 'preview');

            // Ensure directory exists
            $previewDir = dirname(Storage::disk('local')->path($previewPath));
            if (!is_dir($previewDir)) {
                mkdir($previewDir, 0755, true);
            }

            // Generate preview
            $success = $ffmpeg->generatePreview(
                Storage::disk('local')->path($originalPath),
                Storage::disk('local')->path($previewPath),
                $this->startTime,
                $this->duration
            );

            if (!$success) {
                throw new \Exception("Preview generation failed");
            }

            // Upload to DigitalOcean Spaces if configured
            if (config('filesystems.disks.digitalocean')) {
                $doPath = "songs/{$this->song->user_id}/{$this->song->id}/preview/" . basename($previewPath);

                Storage::disk('digitalocean')->put(
                    $doPath,
                    Storage::disk('local')->get($previewPath)
                );

                Log::info("Uploaded preview to DigitalOcean", [
                    'song_id' => $this->song->id,
                    'path' => $doPath
                ]);
            }

            // Update song metadata
            $processingStatus = $this->song->processing_status ?? [];
            $processingStatus['preview'] = 'completed';

            $this->song->update([
                'preview_file' => $previewPath,
                'processing_status' => $processingStatus
            ]);

            Log::info("Preview generation completed", [
                'song_id' => $this->song->id
            ]);

        } catch (\Exception $e) {
            Log::error("Preview generation failed", [
                'song_id' => $this->song->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Preview generation job permanently failed", [
            'song_id' => $this->song->id,
            'error' => $exception->getMessage()
        ]);

        // Update processing status to failed
        $processingStatus = $this->song->processing_status ?? [];
        $processingStatus['preview'] = 'failed';

        $this->song->update([
            'processing_status' => $processingStatus
        ]);
    }
}
