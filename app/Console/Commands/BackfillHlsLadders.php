<?php

namespace App\Console\Commands;

use App\Jobs\Audio\TranscodeToHlsJob;
use App\Models\Song;
use Illuminate\Console\Command;

/**
 * Queue HLS ladder generation for the existing catalog — songs uploaded
 * before adaptive streaming existed have no hls_master_path yet.
 */
class BackfillHlsLadders extends Command
{
    protected $signature = 'hls:backfill {--limit=50 : Max songs to queue in one run} {--dry-run : List candidates without dispatching}';

    protected $description = 'Queue HLS transcoding for published songs that have no adaptive ladder yet';

    public function handle(): int
    {
        $candidates = Song::query()
            ->where('status', 'published')
            ->whereNull('hls_master_path')
            ->whereNotNull('audio_file_original')
            ->orderByDesc('play_count')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to backfill — every published song already has an HLS ladder.');

            return self::SUCCESS;
        }

        foreach ($candidates as $song) {
            if ($this->option('dry-run')) {
                $this->line(" would queue: [{$song->id}] {$song->title}");

                continue;
            }

            TranscodeToHlsJob::dispatch($song);
        }

        $verb = $this->option('dry-run') ? 'Found' : 'Queued';
        $this->info("{$verb} {$candidates->count()} song(s) for HLS transcoding (most-played first).");

        return self::SUCCESS;
    }
}
