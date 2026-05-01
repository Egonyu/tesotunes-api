<?php

namespace App\Console\Commands;

use App\Models\PlayHistory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DistributeListenToEarnPool extends Command
{
    protected $signature = 'credits:distribute-listen-earn
                            {--date= : Date to distribute for (Y-m-d), defaults to yesterday}
                            {--pool= : Override the credit pool size for this run}
                            {--dry-run : Show distribution without awarding credits}';

    protected $description = 'Distribute daily listen-to-earn credit pool proportionally to active listeners';

    public function handle(): int
    {
        $date = $this->option('date')
            ? now()->parse($this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        $poolSize = (int) ($this->option('pool') ?? config('credits.listen_earn_daily_pool', 1000));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s Distributing %s-credit listen-to-earn pool for %s…',
            $dryRun ? '[DRY RUN]' : '',
            number_format($poolSize),
            $date->toDateString()
        ));

        // Aggregate qualified listening seconds per user for the target date
        $listeners = PlayHistory::query()
            ->select('user_id', DB::raw('SUM(duration_played_seconds) as total_seconds'))
            ->whereDate('played_at', $date)
            ->where('completion_percentage', '>=', 90)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->having('total_seconds', '>', 0)
            ->get();

        if ($listeners->isEmpty()) {
            $this->info('No qualifying listeners found for this date.');

            return self::SUCCESS;
        }

        $totalSeconds = $listeners->sum('total_seconds');

        $this->info("Qualifying listeners: {$listeners->count()} | Total seconds: ".number_format($totalSeconds));

        if ($totalSeconds <= 0) {
            $this->warn('Total listening time is zero — nothing to distribute.');

            return self::SUCCESS;
        }

        $awarded = 0;
        $skipped = 0;
        $totalCreditsDistributed = 0;

        $this->withProgressBar($listeners, function ($listener) use ($poolSize, $totalSeconds, $dryRun, &$awarded, &$skipped, &$totalCreditsDistributed) {
            $share = (float) $listener->total_seconds / $totalSeconds;
            $credits = (int) round($poolSize * $share);

            if ($credits < 1) {
                $skipped++;

                return;
            }

            if ($dryRun) {
                $awarded++;
                $totalCreditsDistributed += $credits;

                return;
            }

            $user = User::find($listener->user_id);

            if (! $user) {
                $skipped++;

                return;
            }

            try {
                $user->creditWallet?->addCredits(
                    (float) $credits,
                    'listen_earn',
                    "Listen-to-earn pool share ({$listener->total_seconds}s listened)",
                    [
                        'pool_size' => $poolSize,
                        'seconds_listened' => (int) $listener->total_seconds,
                        'pool_share_pct' => round($share * 100, 2),
                    ]
                );

                $awarded++;
                $totalCreditsDistributed += $credits;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('listen-to-earn award failed', [
                    'user_id' => $listener->user_id,
                    'credits' => $credits,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->newLine(2);
        $this->table(
            ['Metric', 'Value'],
            [
                ['Pool size', number_format($poolSize).' credits'],
                ['Qualifying listeners', $listeners->count()],
                ['Credits distributed', number_format($totalCreditsDistributed)],
                ['Recipients awarded', $awarded],
                ['Skipped', $skipped],
                ['Date', $this->option('date') ?? now()->subDay()->toDateString()],
            ]
        );

        if (! $dryRun) {
            Log::info('Listen-to-earn pool distributed', [
                'date' => $this->option('date') ?? now()->subDay()->toDateString(),
                'pool_size' => $poolSize,
                'listeners' => $listeners->count(),
                'credits_distributed' => $totalCreditsDistributed,
                'awarded' => $awarded,
                'skipped' => $skipped,
            ]);
        }

        return self::SUCCESS;
    }
}
