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

class UpdateArtistCachedStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $artistId;
    public int $timeout = 60; // 1 minute timeout
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(int $artistId)
    {
        $this->artistId = $artistId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $artist = Artist::find($this->artistId);

            if (!$artist) {
                Log::warning("Artist not found for cached stats update", ['artist_id' => $this->artistId]);
                return;
            }

            // Use a database transaction to ensure consistency
            DB::transaction(function () use ($artist) {
                // Use optimized single query for song statistics
                $songStats = $artist->songs()
                    ->selectRaw('
                        COALESCE(SUM(play_count), 0) as total_plays,
                        COALESCE(SUM(revenue), 0) as total_revenue
                    ')
                    ->first();

                // Get followers count
                $followersCount = $artist->followers()->count();

                // Update cached values
                $artist->update([
                    'total_plays_cached' => $songStats->total_plays ?? 0,
                    'total_revenue_cached' => $songStats->total_revenue ?? 0,
                    'followers_count_cached' => $followersCount,
                    'stats_last_updated_at' => now(),
                ]);

                // Clear related cache
                cache()->forget("artist_uploads_{$this->artistId}_" . now()->format('Y_m'));

                Log::info("Updated cached stats for artist", [
                    'artist_id' => $this->artistId,
                    'stage_name' => $artist->stage_name,
                    'total_plays' => $songStats->total_plays ?? 0,
                    'total_revenue' => $songStats->total_revenue ?? 0,
                    'followers_count' => $followersCount,
                ]);
            });

        } catch (\Exception $e) {
            Log::error("Failed to update artist cached stats", [
                'artist_id' => $this->artistId,
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
        Log::error("UpdateArtistCachedStats job failed permanently", [
            'artist_id' => $this->artistId,
            'error' => $exception->getMessage(),
        ]);
    }
}