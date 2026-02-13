<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Jobs\GenerateUserFeedJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class GenerateFeedsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'feed:generate 
                            {--users= : Comma-separated user IDs to generate feeds for}
                            {--limit=1000 : Maximum number of active users to process}
                            {--force : Force regeneration even if recently generated}';

    /**
     * The console command description.
     */
    protected $description = 'Pre-generate feeds for active users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!config('feed.pregenerate.enabled')) {
            $this->warn('Feed pre-generation is disabled in config.');
            return self::FAILURE;
        }

        $startTime = microtime(true);
        $this->info('Starting feed generation...');

        // Get users to generate feeds for
        $users = $this->getUsersToProcess();

        if ($users->isEmpty()) {
            $this->info('No users found to process.');
            return self::SUCCESS;
        }

        $this->info("Processing {$users->count()} users...");

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        $processed = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                // Dispatch job to queue
                GenerateUserFeedJob::dispatch($user, [1, 2]); // Generate first 2 pages
                $processed++;
            } catch (\Exception $e) {
                $failed++;
                $this->error("Failed to dispatch job for user {$user->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);

        $this->info("Feed generation jobs dispatched:");
        $this->info("  - Processed: {$processed}");
        $this->info("  - Failed: {$failed}");
        $this->info("  - Duration: {$duration}s");

        return self::SUCCESS;
    }

    /**
     * Get users to process
     */
    protected function getUsersToProcess()
    {
        // If specific users provided
        if ($this->option('users')) {
            $userIds = explode(',', $this->option('users'));
            return User::whereIn('id', $userIds)->get();
        }

        $limit = (int) $this->option('limit');
        $force = $this->option('force');
        $minActivityThreshold = config('feed.pregenerate.min_activity_threshold', 10);

        $query = User::query()
            ->where('status', 'active')
            ->whereHas('playHistory', function ($q) use ($minActivityThreshold) {
                $q->where('played_at', '>', now()->subDays(7))
                  ->havingRaw('COUNT(*) >= ?', [$minActivityThreshold]);
            });

        // Skip recently generated unless forced
        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('last_feed_generated_at')
                  ->orWhere('last_feed_generated_at', '<', now()->subHour());
            });
        }

        return $query->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();
    }
}
