<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GenerateUserFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    protected User $user;
    protected array $pages;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, array $pages = [1])
    {
        $this->user = $user;
        $this->pages = $pages;
        $this->onQueue('feed');
    }

    /**
     * Execute the job.
     */
    public function handle(FeedService $feedService): void
    {
        $startTime = microtime(true);

        try {
            foreach ($this->pages as $page) {
                // Generate and cache feed for this page
                $feed = $feedService->forUser($this->user)
                    ->withFollowedArtists()
                    ->withFriendActivity()
                    ->withPlatformEvents()
                    ->withForumActivity()
                    ->withPollActivity()
                    ->withRecommendations()
                    ->paginate($page);

                Log::info("Pre-generated feed for user", [
                    'user_id' => $this->user->id,
                    'page' => $page,
                    'items_count' => $feed->count(),
                    'duration_ms' => (microtime(true) - $startTime) * 1000,
                ]);
            }

            // Update user's last feed generation timestamp
            $this->user->update([
                'last_feed_generated_at' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to generate feed for user", [
                'user_id' => $this->user->id,
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
        Log::error("Feed generation job failed permanently", [
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
