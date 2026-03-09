<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\FeedItem;
use App\Services\FeedItemService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillFeedItems extends Command
{
    protected $signature = 'feed:backfill
                            {--since= : Only backfill activities created after this date (default: 30 days ago)}
                            {--module= : Only backfill a specific module (music, events, social, awards, store, sacco, forum, podcast)}
                            {--limit=5000 : Maximum number of activities to process}
                            {--batch-size=100 : Number of activities to process per batch}
                            {--dry-run : Preview counts without inserting}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Backfill feed_items from existing activities table for the Edula discovery feed';

    private array $moduleTypeMap = [
        'music' => ['uploaded_song', 'distributed_song', 'featured_song', 'released_album', 'created_playlist'],
        'events' => ['created_event'],
        'social' => ['liked_song', 'liked_post', 'commented_song', 'followed_artist', 'shared_song'],
        'awards' => ['award_voted', 'award_nominated'],
        'store' => ['store_created', 'product_listed', 'product_purchased', 'product_reviewed'],
        'sacco' => ['sacco_joined', 'dividend_received'],
        'forum' => ['thread_created', 'reply_posted', 'poll_created'],
        'podcast' => ['episode_published'],
    ];

    public function handle(): int
    {
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))
            : now()->subDays(30);

        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');
        $module = $this->option('module');

        $this->info("Edula Feed Backfill");
        $this->info("===================");
        $this->info("Since: {$since->toDateTimeString()}");
        $this->info("Limit: {$limit}");
        $this->info("Module: " . ($module ?: 'all'));
        $this->info("Dry run: " . ($dryRun ? 'yes' : 'no'));
        $this->newLine();

        // Build query
        $query = Activity::query()
            ->where('created_at', '>=', $since)
            ->whereHas('user')
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        // Filter by module types if specified
        if ($module) {
            if (! isset($this->moduleTypeMap[$module])) {
                $this->error("Unknown module: {$module}. Valid: " . implode(', ', array_keys($this->moduleTypeMap)));
                return self::FAILURE;
            }
            $query->whereIn('type', $this->moduleTypeMap[$module]);
        }

        $totalCount = (clone $query)->count();
        $this->info("Found {$totalCount} activities to process.");

        if ($totalCount === 0) {
            $this->info('Nothing to backfill.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['Module', 'Activity Types', 'Count'],
                $this->getDryRunStats($query)
            );
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Proceed with backfilling {$totalCount} activities?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $processed = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $startTime = microtime(true);

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $query->chunk($batchSize, function ($activities) use (&$processed, &$created, &$skipped, &$failed, $bar) {
            foreach ($activities as $activity) {
                $processed++;

                // Skip if feed item already exists for this activity
                $exists = FeedItem::where('extras->source_activity_id', $activity->id)->exists();
                if ($exists) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                try {
                    $feedItem = FeedItemService::createFromActivity($activity);

                    if ($feedItem) {
                        $created++;
                    } else {
                        $skipped++; // Rate-limited or aggregated
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("Failed activity #{$activity->id} ({$activity->type}): {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);

        $this->info("Backfill complete!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $processed],
                ['Created', $created],
                ['Skipped (existing/aggregated)', $skipped],
                ['Failed', $failed],
                ['Duration', "{$duration}s"],
            ]
        );

        if ($failed > 0) {
            $this->warn("{$failed} activities failed. Review logs for details.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function getDryRunStats($query): array
    {
        $stats = [];
        $activities = (clone $query)->get()->groupBy('type');

        $reverseMap = [];
        foreach ($this->moduleTypeMap as $mod => $types) {
            foreach ($types as $type) {
                $reverseMap[$type] = $mod;
            }
        }

        $moduleGroups = [];
        foreach ($activities as $type => $items) {
            $mod = $reverseMap[$type] ?? 'other';
            if (! isset($moduleGroups[$mod])) {
                $moduleGroups[$mod] = ['types' => [], 'count' => 0];
            }
            $moduleGroups[$mod]['types'][] = $type;
            $moduleGroups[$mod]['count'] += $items->count();
        }

        foreach ($moduleGroups as $mod => $data) {
            $stats[] = [$mod, implode(', ', $data['types']), $data['count']];
        }

        return $stats;
    }
}
