<?php

namespace App\Jobs\Audio;

use App\Models\Song;
use App\Services\Audio\FFmpegService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Build the adaptive-streaming ladder for a song: one HLS rendition per
 * configured bitrate (64/128/320 kbps) plus a master playlist, uploaded to
 * the song storage disk (Spaces in production, served via the CDN).
 *
 * Failure or absence of ffmpeg never affects the song's publish lifecycle —
 * stream_url remains the progressive fallback and only processing_status.hls
 * records the outcome.
 */
class TranscodeToHlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 900;

    public $backoff = [300];

    public function __construct(public Song $song)
    {
        $this->onQueue('low');
    }

    public function handle(FFmpegService $ffmpeg): void
    {
        if (! config('music.hls.enabled', true)) {
            return;
        }

        if (! $ffmpeg->isAvailable()) {
            Log::warning('HLS transcode skipped — ffmpeg unavailable', ['song_id' => $this->song->id]);
            $this->mergeProcessingStatus(['hls' => 'skipped_no_ffmpeg']);

            return;
        }

        [$sourceDisk, $sourcePath] = $this->locateSourceAudio();

        if (! $sourcePath) {
            Log::warning('HLS transcode skipped — source audio not found', ['song_id' => $this->song->id]);
            $this->mergeProcessingStatus(['hls' => 'skipped_no_source']);

            return;
        }

        $workDir = storage_path('app/tmp/hls-'.$this->song->id.'-'.uniqid());
        File::ensureDirectoryExists($workDir);

        try {
            $localSource = $workDir.DIRECTORY_SEPARATOR.'source.'.pathinfo($sourcePath, PATHINFO_EXTENSION);
            file_put_contents($localSource, Storage::disk($sourceDisk)->get($sourcePath));

            $bitrates = (array) config('music.hls.bitrates', [64, 128, 320]);
            $segmentSeconds = (int) config('music.hls.segment_seconds', 6);
            $renditions = [];

            foreach ($bitrates as $bitrate) {
                $renditionDir = $workDir.DIRECTORY_SEPARATOR.$bitrate;

                if (! $ffmpeg->generateHlsRendition($localSource, $renditionDir, (int) $bitrate, $segmentSeconds)) {
                    throw new \RuntimeException("HLS rendition at {$bitrate}kbps failed");
                }

                $renditions[] = (int) $bitrate;
            }

            file_put_contents(
                $workDir.DIRECTORY_SEPARATOR.'master.m3u8',
                $this->masterPlaylist($renditions)
            );

            $remoteBase = trim(config('music.hls.base_path', 'hls/songs'), '/').'/'.$this->song->id;
            $this->uploadTree($workDir, $sourceDisk, $remoteBase, basename($localSource));

            $this->song->forceFill([
                'hls_master_path' => $remoteBase.'/master.m3u8',
                'hls_generated_at' => now(),
            ])->save();

            $this->mergeProcessingStatus(['hls' => 'completed']);

            Log::info('HLS ladder generated', [
                'song_id' => $this->song->id,
                'renditions' => $renditions,
                'master' => $remoteBase.'/master.m3u8',
            ]);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('HLS transcode permanently failed', [
            'song_id' => $this->song->id,
            'error' => $exception->getMessage(),
        ]);

        $this->mergeProcessingStatus(['hls' => 'failed']);
    }

    /**
     * Find the song's source audio across the disks uploads may land on.
     *
     * @return array{0: string, 1: ?string} [disk, path|null]
     */
    private function locateSourceAudio(): array
    {
        $candidates = array_values(array_unique(array_filter([
            config('filesystems.default'),
            'digitalocean',
            'public',
            'local',
        ])));

        $paths = array_filter([
            $this->song->audio_file_original,
            $this->song->audio_file_320,
            $this->song->audio_file_128,
        ]);

        foreach ($candidates as $disk) {
            if (! config("filesystems.disks.{$disk}")) {
                continue;
            }

            foreach ($paths as $path) {
                try {
                    if (Storage::disk($disk)->exists($path)) {
                        return [$disk, $path];
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return [$candidates[0] ?? 'local', null];
    }

    /**
     * @param  array<int>  $renditions
     */
    private function masterPlaylist(array $renditions): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        foreach ($renditions as $bitrate) {
            // ~10% container overhead over the audio bitrate.
            $bandwidth = (int) ($bitrate * 1000 * 1.1);
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},CODECS=\"mp4a.40.2\"";
            $lines[] = "{$bitrate}/index.m3u8";
        }

        return implode("\n", $lines)."\n";
    }

    private function uploadTree(string $localDir, string $disk, string $remoteBase, string $excludeFile): void
    {
        foreach (File::allFiles($localDir) as $file) {
            if ($file->getFilename() === $excludeFile) {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());

            Storage::disk($disk)->put(
                $remoteBase.'/'.$relative,
                $file->getContents(),
                ['visibility' => 'public']
            );
        }
    }

    private function mergeProcessingStatus(array $updates): void
    {
        $current = is_array($this->song->processing_status) ? $this->song->processing_status : [];

        $this->song->forceFill([
            'processing_status' => array_merge($current, $updates),
        ])->save();
    }
}
