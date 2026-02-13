<?php

namespace App\Jobs;

use App\Models\Artist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateAllArtistCachedStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes timeout
    public int $tries = 3;
    protected int $batchSize;

    /**
     * Create a new job instance.
     */
    public function __construct(int $batchSize = 100)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting batch update of all artist cached stats", [
                'batch_size' => $this->batchSize
            ]);

            $totalArtists = Artist::count();
            $processed = 0;
            $startTime = now();

            // Process artists in batches to avoid memory issues
            Artist::chunk($this->batchSize, function ($artists) use (&$processed) {
                $artistIds = $artists->pluck('id')->toArray();

                // Batch update song statistics using raw SQL for efficiency
                $this->updateSongStatistics($artistIds);

                // Batch update follower counts
                $this->updateFollowerCounts($artistIds);

                $processed += count($artistIds);

                Log::info("Processed batch of artists", [
                    'processed' => $processed,
                    'artist_ids' => $artistIds
                ]);
            });

            $duration = $startTime->diffInSeconds(now());

            Log::info("Completed batch update of all artist cached stats", [
                'total_processed' => $processed,
                'duration_seconds' => $duration,
                'artists_per_second' => $processed > 0 ? round($processed / $duration, 2) : 0
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update all artist cached stats", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Update song statistics for a batch of artists
     */
    private function updateSongStatistics(array $artistIds): void
    {
        // Use raw SQL to efficiently calculate and update song stats
        DB::statement("
            UPDATE artists
            SET
                total_plays_cached = COALESCE((
                    SELECT SUM(play_count)
                    FROM songs
                    WHERE songs.artist_id = artists.id
                    AND songs.deleted_at IS NULL
                ), 0),
                total_revenue_cached = COALESCE((
                    SELECT SUM(revenue)
                    FROM songs
                    WHERE songs.artist_id = artists.id
                    AND songs.deleted_at IS NULL
                ), 0),
                stats_last_updated_at = NOW()
            WHERE artists.id IN (" . implode(',', array_map('intval', $artistIds)) . ")
        ");
    }

    /**
     * Update follower counts for a batch of artists
     */
    private function updateFollowerCounts(array $artistIds): void
    {
        // Use raw SQL to efficiently calculate and update follower counts
        DB::statement("
            UPDATE artists
            SET followers_count_cached = COALESCE((
                SELECT COUNT(*)
                FROM user_follows
                WHERE user_follows.following_id = artists.id
                AND user_follows.type = 'App\\\\Models\\\\Artist'
                AND user_follows.deleted_at IS NULL
            ), 0)
            WHERE artists.id IN (" . implode(',', array_map('intval', $artistIds)) . ")
        ");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("UpdateAllArtistCachedStats job failed permanently", [
            'error' => $exception->getMessage(),
        ]);
    }
}