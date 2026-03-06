<?php

namespace App\Jobs;

use App\Models\PodcastEpisode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Process podcast episode upload — transcode to multiple quality levels.
 *
 * Requires FFmpeg to be installed on the server.
 * Falls back gracefully if FFmpeg is unavailable.
 */
class ProcessEpisodeUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        protected PodcastEpisode $episode,
    ) {}

    public function handle(): void
    {
        $disk = Storage::disk(config('podcast.storage.primary_driver', 'local'));
        $originalPath = $this->episode->audio_file_path;

        if (!$originalPath || !$disk->exists($originalPath)) {
            Log::warning('ProcessEpisodeUploadJob: audio file not found', [
                'episode_id' => $this->episode->id,
                'path' => $originalPath,
            ]);
            return;
        }

        $qualities = [
            'low' => ['bitrate' => '64k', 'suffix' => '_64'],
            'medium' => ['bitrate' => '128k', 'suffix' => '_128'],
            'high' => ['bitrate' => '320k', 'suffix' => '_320'],
        ];

        $basePath = pathinfo($originalPath, PATHINFO_DIRNAME);
        $extension = pathinfo($originalPath, PATHINFO_EXTENSION);
        $filename = pathinfo($originalPath, PATHINFO_FILENAME);

        // Check if FFmpeg is available
        $ffmpegPath = config('podcast.ffmpeg_path', 'ffmpeg');
        $checkCommand = PHP_OS_FAMILY === 'Windows'
            ? "where {$ffmpegPath} 2>NUL"
            : "which {$ffmpegPath} 2>/dev/null";

        exec($checkCommand, $output, $returnCode);
        if ($returnCode !== 0) {
            Log::info('ProcessEpisodeUploadJob: FFmpeg not available, skipping transcoding', [
                'episode_id' => $this->episode->id,
            ]);
            return;
        }

        $transcodedPaths = [];

        foreach ($qualities as $quality => $settings) {
            try {
                $outputFilename = "{$filename}{$settings['suffix']}.{$extension}";
                $outputPath = "{$basePath}/{$outputFilename}";

                // Download original to temp for FFmpeg processing
                $tempInput = tempnam(sys_get_temp_dir(), 'ep_in_');
                $tempOutput = tempnam(sys_get_temp_dir(), 'ep_out_') . ".{$extension}";
                file_put_contents($tempInput, $disk->get($originalPath));

                $command = sprintf(
                    '%s -i %s -b:a %s -y %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($tempInput),
                    escapeshellarg($settings['bitrate']),
                    escapeshellarg($tempOutput)
                );

                exec($command, $cmdOutput, $cmdReturn);

                if ($cmdReturn === 0 && file_exists($tempOutput)) {
                    $disk->put($outputPath, file_get_contents($tempOutput));
                    $transcodedPaths[$quality] = $outputPath;

                    Log::info("Transcoded episode to {$quality}", [
                        'episode_id' => $this->episode->id,
                        'path' => $outputPath,
                    ]);
                }

                @unlink($tempInput);
                @unlink($tempOutput);
            } catch (\Exception $e) {
                Log::warning("Transcoding failed for {$quality}", [
                    'episode_id' => $this->episode->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($transcodedPaths)) {
            $this->episode->update([
                'transcoded_paths' => $transcodedPaths,
            ]);
        }
    }
}
