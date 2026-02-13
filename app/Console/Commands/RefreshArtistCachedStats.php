<?php

namespace App\Console\Commands;

use App\Jobs\UpdateAllArtistCachedStats;
use App\Models\Artist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshArtistCachedStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'artist:refresh-stats
                            {--queue=stats : Queue to use for the job}
                            {--batch-size=100 : Number of artists to process per batch}
                            {--sync : Run synchronously instead of queuing}
                            {--artist= : Specific artist ID to refresh}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh cached statistics for all artists (total plays, revenue, followers)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $specificArtistId = $this->option('artist');
        $batchSize = (int) $this->option('batch-size');
        $queue = $this->option('queue');
        $sync = $this->option('sync');

        if ($specificArtistId) {
            return $this->refreshSingleArtist($specificArtistId);
        }

        $totalArtists = Artist::count();

        if ($totalArtists === 0) {
            $this->info('No artists found to refresh.');
            return self::SUCCESS;
        }

        $this->info("Found {$totalArtists} artists to refresh.");

        if ($this->confirm('This will refresh cached stats for all artists. Continue?')) {
            if ($sync) {
                return $this->runSynchronously($batchSize);
            } else {
                return $this->queueRefreshJob($batchSize, $queue);
            }
        }

        $this->info('Operation cancelled.');
        return self::SUCCESS;
    }

    /**
     * Refresh stats for a single artist
     */
    private function refreshSingleArtist(int $artistId): int
    {
        $artist = Artist::find($artistId);

        if (!$artist) {
            $this->error("Artist with ID {$artistId} not found.");
            return self::FAILURE;
        }

        $this->info("Refreshing stats for artist: {$artist->stage_name}");

        try {
            $artist->refreshCachedStats();
            $this->info('Stats refreshed successfully!');

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Plays', number_format($artist->total_plays_cached)],
                    ['Total Revenue', '$' . number_format($artist->total_revenue_cached, 2)],
                    ['Followers', number_format($artist->followers_count_cached)],
                    ['Last Updated', $artist->stats_last_updated_at],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to refresh stats: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Queue the refresh job
     */
    private function queueRefreshJob(int $batchSize, string $queue): int
    {
        try {
            UpdateAllArtistCachedStats::dispatch($batchSize)->onQueue($queue);

            $this->info("Queued artist stats refresh job on '{$queue}' queue with batch size of {$batchSize}.");
            $this->info('You can monitor the job progress in your queue worker logs.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to queue refresh job: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Run the refresh synchronously
     */
    private function runSynchronously(int $batchSize): int
    {
        $this->info('Running synchronously...');

        $bar = $this->output->createProgressBar(Artist::count());
        $bar->start();

        $processed = 0;
        $failed = 0;

        Artist::chunk($batchSize, function ($artists) use ($bar, &$processed, &$failed) {
            foreach ($artists as $artist) {
                try {
                    $artist->refreshCachedStats();
                    $processed++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to refresh {$artist->stage_name}: {$e->getMessage()}");
                    $failed++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Completed! Processed: {$processed}, Failed: {$failed}");

        // Clear all upload caches
        $this->info('Clearing upload caches...');
        $this->clearUploadCaches();

        return self::SUCCESS;
    }

    /**
     * Clear all upload caches
     */
    private function clearUploadCaches(): void
    {
        $currentMonth = now()->format('Y_m');
        $lastMonth = now()->subMonth()->format('Y_m');

        Artist::chunk(100, function ($artists) use ($currentMonth, $lastMonth) {
            foreach ($artists as $artist) {
                Cache::forget("artist_uploads_{$artist->id}_{$currentMonth}");
                Cache::forget("artist_uploads_{$artist->id}_{$lastMonth}");
            }
        });

        $this->info('Upload caches cleared.');
    }
}