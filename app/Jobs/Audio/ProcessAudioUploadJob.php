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

class ProcessAudioUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900; // 15 minutes
    public $backoff = [120, 300, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Song $song,
        public string $tempFilePath
    ) {
        $this->onQueue('high'); // High priority for user uploads
    }

    /**
     * Execute the job.
     */
    public function handle(FFmpegService $ffmpeg, MusicStorageService $storage): void
    {
        try {
            Log::info("Starting audio upload processing", [
                'song_id' => $this->song->id,
                'temp_path' => $this->tempFilePath
            ]);

            // 1. Extract metadata from uploaded file
            $metadata = $ffmpeg->extractMetadata(
                Storage::disk('local')->path($this->tempFilePath)
            );

            if ($metadata) {
                $this->song->update([
                    'duration' => (int) $metadata['duration'],
                    'bitrate' => $metadata['bitrate'],
                    'sample_rate' => $metadata['sample_rate'],
                    'file_size' => $metadata['size'],
                ]);

                Log::info("Extracted audio metadata", [
                    'song_id' => $this->song->id,
                    'duration' => $metadata['duration'],
                    'bitrate' => $metadata['bitrate']
                ]);
            }

            // 2. Move to permanent storage as original
            $originalPath = $storage->getSongPath($this->song, 'original');

            // Ensure directory exists
            $originalDir = dirname(Storage::disk('local')->path($originalPath));
            if (!is_dir($originalDir)) {
                mkdir($originalDir, 0755, true);
            }

            Storage::disk('local')->move($this->tempFilePath, $originalPath);

            $this->song->update([
                'audio_file' => $originalPath
            ]);

            // 3. Upload original to DigitalOcean Spaces (if configured)
            if (config('filesystems.disks.digitalocean')) {
                $doPath = "songs/{$this->song->user_id}/{$this->song->id}/original/" . basename($originalPath);

                Storage::disk('digitalocean')->put(
                    $doPath,
                    Storage::disk('local')->get($originalPath)
                );

                Log::info("Uploaded original to DigitalOcean Spaces", [
                    'song_id' => $this->song->id,
                    'path' => $doPath
                ]);
            }

            // 4. Queue transcoding jobs
            Log::info("Queueing transcoding jobs", ['song_id' => $this->song->id]);

            TranscodeAudioJob::dispatch($this->song, '320kbps')
                ->onQueue('high');

            TranscodeAudioJob::dispatch($this->song, '128kbps')
                ->onQueue('high');

            // 5. Queue preview generation
            GeneratePreviewJob::dispatch($this->song, 0, 30)
                ->onQueue('default');

            // 6. Queue waveform generation (low priority)
            GenerateWaveformJob::dispatch($this->song, '1920x200', '#3b82f6')
                ->onQueue('low');

            // 7. Update song status
            $this->song->update([
                'status' => 'processing',
                'processing_status' => [
                    'upload' => 'completed',
                    'metadata' => 'completed',
                    '320kbps' => 'pending',
                    '128kbps' => 'pending',
                    'preview' => 'pending',
                    'waveform' => 'pending'
                ]
            ]);

            Log::info("Audio upload processing completed, transcoding queued", [
                'song_id' => $this->song->id
            ]);

        } catch (\Exception $e) {
            Log::error("Audio upload processing failed", [
                'song_id' => $this->song->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update song status to failed
            $this->song->update([
                'status' => 'failed',
                'processing_status' => [
                    'upload' => 'failed',
                    'error' => $e->getMessage()
                ]
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Audio upload processing job permanently failed", [
            'song_id' => $this->song->id,
            'error' => $exception->getMessage()
        ]);

        $this->song->update([
            'status' => 'failed',
            'processing_status' => [
                'upload' => 'failed',
                'error' => $exception->getMessage()
            ]
        ]);
    }
}
