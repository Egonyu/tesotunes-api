<?php

namespace App\Jobs;

use App\Models\Song;
use App\Services\AudioWatermarkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WatermarkAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The song instance
     */
    public Song $song;

    /**
     * Quality version to watermark (e.g., '320kbps', '128kbps', 'original')
     */
    public string $quality;

    /**
     * The number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out
     */
    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance
     */
    public function __construct(Song $song, string $quality = '320kbps')
    {
        $this->song = $song;
        $this->quality = $quality;
    }

    /**
     * Execute the job
     */
    public function handle(AudioWatermarkService $watermarkService): void
    {
        Log::info("Starting audio watermarking for song {$this->song->id} ({$this->quality})");

        try {
            // Get the audio file path
            $audioPath = $this->getAudioPath();

            if (!$audioPath || !file_exists($audioPath)) {
                Log::error("Audio file not found for song {$this->song->id}: {$audioPath}");
                return;
            }

            // Define watermarked output path
            $outputPath = $this->getWatermarkedPath();

            // Ensure output directory exists
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Perform watermarking
            Log::info("Watermarking: {$audioPath} -> {$outputPath}");
            
            $success = $watermarkService->watermarkAudio($audioPath, $outputPath, [
                'positions' => ['start', 'middle', 'end'],
                'volume' => 0.25, // 25% volume for watermark
            ]);

            if ($success) {
                Log::info("Successfully watermarked song {$this->song->id} ({$this->quality})");

                // Update song metadata
                $this->updateSongMetadata($outputPath);

                // Optionally: Replace original file with watermarked version
                if (config('music.watermark_replace_original', false)) {
                    $this->replaceOriginalFile($audioPath, $outputPath);
                }
            } else {
                Log::error("Failed to watermark song {$this->song->id} ({$this->quality})");
            }

        } catch (\Exception $e) {
            Log::error("Error watermarking song {$this->song->id}: " . $e->getMessage(), [
                'song_id' => $this->song->id,
                'quality' => $this->quality,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Get the audio file path based on quality
     */
    protected function getAudioPath(): ?string
    {
        $userId = $this->song->user_id;
        $songId = $this->song->id;

        $paths = [
            'original' => storage_path("app/music/{$userId}/{$songId}/original/" . $this->song->file_path),
            '320kbps' => storage_path("app/music/{$userId}/{$songId}/320kbps/" . basename($this->song->file_path)),
            '128kbps' => storage_path("app/music/{$userId}/{$songId}/128kbps/" . basename($this->song->file_path)),
        ];

        return $paths[$this->quality] ?? null;
    }

    /**
     * Get the watermarked output path
     */
    protected function getWatermarkedPath(): string
    {
        $userId = $this->song->user_id;
        $songId = $this->song->id;
        $fileName = basename($this->song->file_path);

        return storage_path("app/music/{$userId}/{$songId}/watermarked/{$this->quality}_{$fileName}");
    }

    /**
     * Update song metadata with watermarked file info
     */
    protected function updateSongMetadata(string $watermarkedPath): void
    {
        // Store watermarked file path in song metadata
        $metadata = $this->song->metadata ?? [];
        $metadata['watermarked_files'] = $metadata['watermarked_files'] ?? [];
        $metadata['watermarked_files'][$this->quality] = str_replace(storage_path('app/'), '', $watermarkedPath);
        $metadata['watermarked_at'] = now()->toIso8601String();

        $this->song->update(['metadata' => $metadata]);
    }

    /**
     * Replace original file with watermarked version
     */
    protected function replaceOriginalFile(string $originalPath, string $watermarkedPath): bool
    {
        try {
            // Backup original file
            $backupPath = $originalPath . '.original.backup';
            
            if (!file_exists($backupPath)) {
                copy($originalPath, $backupPath);
                Log::info("Created backup: {$backupPath}");
            }

            // Replace original with watermarked
            copy($watermarkedPath, $originalPath);
            Log::info("Replaced original file with watermarked version: {$originalPath}");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to replace original file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Watermarking job failed permanently for song {$this->song->id}", [
            'song_id' => $this->song->id,
            'quality' => $this->quality,
            'error' => $exception->getMessage()
        ]);

        // Optionally: Notify admin or update song status
    }
}
