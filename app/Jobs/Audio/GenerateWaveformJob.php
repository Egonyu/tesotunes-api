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

class GenerateWaveformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 300; // 5 minutes
    public $backoff = [60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Song $song,
        public string $size = '1920x200',
        public string $color = 'blue'
    ) {
        $this->onQueue('low'); // Low priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(FFmpegService $ffmpeg, MusicStorageService $storage): void
    {
        try {
            Log::info("Starting waveform generation", [
                'song_id' => $this->song->id,
                'size' => $this->size
            ]);

            // Get original file path
            $originalPath = $storage->getSongPath($this->song, 'original');

            if (!Storage::disk('local')->exists($originalPath)) {
                throw new \Exception("Original audio file not found: {$originalPath}");
            }

            // Prepare output path for waveform image
            $waveformPath = "songs/{$this->song->user_id}/{$this->song->id}/waveform.png";

            // Ensure directory exists
            $waveformDir = dirname(Storage::disk('local')->path($waveformPath));
            if (!is_dir($waveformDir)) {
                mkdir($waveformDir, 0755, true);
            }

            // Generate waveform
            $success = $ffmpeg->generateWaveform(
                Storage::disk('local')->path($originalPath),
                Storage::disk('local')->path($waveformPath),
                $this->size,
                $this->color
            );

            if (!$success) {
                throw new \Exception("Waveform generation failed");
            }

            // Upload to DigitalOcean Spaces if configured
            if (config('filesystems.disks.digitalocean')) {
                $doPath = "songs/{$this->song->user_id}/{$this->song->id}/waveform.png";

                Storage::disk('digitalocean')->put(
                    $doPath,
                    Storage::disk('local')->get($waveformPath)
                );

                Log::info("Uploaded waveform to DigitalOcean", [
                    'song_id' => $this->song->id,
                    'path' => $doPath
                ]);
            }

            // Update song with waveform URL
            $this->song->update([
                'waveform_url' => Storage::disk('public')->url($waveformPath)
            ]);

            Log::info("Waveform generation completed", [
                'song_id' => $this->song->id
            ]);

        } catch (\Exception $e) {
            Log::error("Waveform generation failed", [
                'song_id' => $this->song->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Don't throw - waveform is optional feature
            // Just log the error and continue
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning("Waveform generation job failed (non-critical)", [
            'song_id' => $this->song->id,
            'error' => $exception->getMessage()
        ]);
    }
}
