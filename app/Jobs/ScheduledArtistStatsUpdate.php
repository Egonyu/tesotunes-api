<?php

namespace App\Jobs;

use App\Models\Artist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScheduledArtistStatsUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes timeout
    public int $tries = 2;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $startTime = now();

            // Find artists with stale cached stats (older than 6 hours)
            $staleThreshold = now()->subHours(6);

            $staleArtistsQuery = Artist::where(function ($query) use ($staleThreshold) {
                $query->whereNull('stats_last_updated_at')
                      ->orWhere('stats_last_updated_at', '<', $staleThreshold);
            });

            $staleCount = $staleArtistsQuery->count();

            if ($staleCount === 0) {
                Log::info('No artists with stale cached stats found');
                return;
            }

            Log::info("Found {$staleCount} artists with stale cached stats");

            // Process in smaller batches to avoid overwhelming the queue
            $batchSize = 25;
            $processed = 0;

            $staleArtistsQuery->chunk($batchSize, function ($artists) use (&$processed) {
                foreach ($artists as $artist) {
                    // Dispatch individual jobs with staggered delays to smooth the load
                    UpdateArtistCachedStats::dispatch($artist->id)
                        ->onQueue('stats')
                        ->delay(now()->addSeconds($processed * 2)); // 2-second intervals

                    $processed++;
                }
            });

            $duration = $startTime->diffInSeconds(now());

            Log::info("Scheduled artist stats update completed", [
                'artists_queued' => $processed,
                'duration_seconds' => $duration,
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to schedule artist stats updates", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ScheduledArtistStatsUpdate job failed permanently", [
            'error' => $exception->getMessage(),
        ]);
    }
}