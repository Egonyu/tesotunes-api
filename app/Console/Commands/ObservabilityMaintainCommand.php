<?php

namespace App\Console\Commands;

use App\Models\ObservabilityEvent;
use App\Models\ObservabilityIntegritySnapshot;
use App\Models\ObservabilityRollupHourly;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ObservabilityMaintainCommand extends Command
{
    protected $signature = 'observability:maintain
        {--rollup-hours=48 : Number of recent hours to refresh into hourly rollups}
        {--prune-raw-days=180 : Delete normalized observability events older than this many days}
        {--prune-rollup-days=395 : Delete observability rollups older than this many days}
        {--prune-integrity-days=365 : Delete integrity snapshots older than this many days}';

    protected $description = 'Refresh observability hourly rollups and prune expired observability data';

    public function handle(): int
    {
        $rollupHours = max(1, (int) $this->option('rollup-hours'));
        $rawDays = max(1, (int) $this->option('prune-raw-days'));
        $rollupDays = max(1, (int) $this->option('prune-rollup-days'));
        $integrityDays = max(1, (int) $this->option('prune-integrity-days'));

        $cutoff = now()->subHours($rollupHours)->startOfHour();
        $buckets = ObservabilityEvent::query()
            ->where('occurred_at', '>=', $cutoff)
            ->selectRaw("DATE_FORMAT(occurred_at, '%Y-%m-%d %H:00:00') as bucket_start")
            ->groupBy('bucket_start')
            ->pluck('bucket_start');

        $rollupRows = 0;

        foreach ($buckets as $bucketStart) {
            $bucket = Carbon::parse($bucketStart);
            $bucketEnd = (clone $bucket)->copy()->endOfHour();
            $events = ObservabilityEvent::query()
                ->whereBetween('occurred_at', [$bucket, $bucketEnd])
                ->get();

            $dimensionMaps = [
                'domain' => $events->groupBy(fn (ObservabilityEvent $event) => (string) ($event->domain ?: 'unknown')),
                'category' => $events->groupBy(fn (ObservabilityEvent $event) => (string) ($event->category ?: 'unknown')),
                'host' => $events->groupBy(fn (ObservabilityEvent $event) => (string) ($event->host ?: $event->target_resource_id ?: 'unknown')),
            ];

            foreach ($dimensionMaps as $dimensionType => $groups) {
                foreach ($groups as $dimensionKey => $group) {
                    $distinctSources = $group->pluck('source_ip')->filter()->unique()->count();
                    $avgRisk = (int) round((float) ($group->avg('risk_score') ?? 0));

                    ObservabilityRollupHourly::query()->updateOrCreate(
                        [
                            'bucket_start' => $bucket,
                            'dimension_type' => $dimensionType,
                            'dimension_key' => $dimensionKey !== '' ? $dimensionKey : 'unknown',
                        ],
                        [
                            'total_events' => $group->count(),
                            'blocked_events' => $group->where('outcome', 'blocked')->count(),
                            'failed_events' => $group->where('outcome', 'failed')->count(),
                            'suspicious_events' => $group->where('outcome', 'suspicious')->count(),
                            'successful_suspicious_events' => $group
                                ->filter(fn (ObservabilityEvent $event) => $event->outcome === 'success' && (int) $event->risk_score >= 65)
                                ->count(),
                            'distinct_sources' => $distinctSources,
                            'avg_risk_score' => max(0, min(100, $avgRisk)),
                            'metadata' => [
                                'max_risk_score' => (int) ($group->max('risk_score') ?? 0),
                                'top_outcome' => (string) ($group->pluck('outcome')->filter()->countBy()->sortDesc()->keys()->first() ?? 'unknown'),
                            ],
                        ]
                    );

                    $rollupRows++;
                }
            }
        }

        $prunedEvents = ObservabilityEvent::query()
            ->where('occurred_at', '<', now()->subDays($rawDays))
            ->delete();

        $prunedRollups = ObservabilityRollupHourly::query()
            ->where('bucket_start', '<', now()->subDays($rollupDays))
            ->delete();

        $prunedIntegrity = ObservabilityIntegritySnapshot::query()
            ->where('observed_at', '<', now()->subDays($integrityDays))
            ->delete();

        $this->table(
            ['Metric', 'Value'],
            [
                ['rollup_rows_refreshed', $rollupRows],
                ['events_pruned', $prunedEvents],
                ['rollups_pruned', $prunedRollups],
                ['integrity_snapshots_pruned', $prunedIntegrity],
            ]
        );

        return self::SUCCESS;
    }
}
