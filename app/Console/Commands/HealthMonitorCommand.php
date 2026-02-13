<?php

namespace App\Console\Commands;

use App\Services\Monitoring\AlertingService;
use App\Services\Monitoring\HttpClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Periodic health probe — runs every 5 minutes via scheduler.
 *
 * Checks: DB connectivity, queue depth, failed jobs, disk space,
 * cache health, error rate, and external service metrics.
 * Fires alerts via AlertingService when thresholds are breached.
 */
class HealthMonitorCommand extends Command
{
    protected $signature = 'monitor:health';
    protected $description = 'Run system health checks and send alerts when thresholds are exceeded';

    // ── Thresholds ───────────────────────────────────────────────
    private const MAX_QUEUE_DEPTH      = 500;    // pending jobs
    private const MAX_FAILED_JOBS      = 10;     // in the last hour
    private const MIN_DISK_FREE_GB     = 2;      // GB
    private const MAX_ERROR_RATE       = 50;     // errors/hour in log
    private const MAX_DB_LATENCY_MS    = 200;    // milliseconds
    private const MAX_CACHE_LATENCY_MS = 50;     // milliseconds
    private const MAX_HTTP_ERROR_RATE  = 0.5;    // 50% failure rate

    public function handle(): int
    {
        $results = [];
        $alerting = app(AlertingService::class);

        $results['database']     = $this->checkDatabase($alerting);
        $results['queue']        = $this->checkQueue($alerting);
        $results['failed_jobs']  = $this->checkFailedJobs($alerting);
        $results['disk']         = $this->checkDisk($alerting);
        $results['cache']        = $this->checkCache($alerting);
        $results['http_clients'] = $this->checkHttpMetrics($alerting);

        // Store snapshot for the health dashboard
        Cache::put('health:last_check', [
            'timestamp' => now()->toIso8601String(),
            'results'   => $results,
        ], 600); // 10 minutes

        $failedChecks = collect($results)->filter(fn ($r) => ($r['status'] ?? 'ok') !== 'ok')->keys();

        if ($failedChecks->isNotEmpty()) {
            $this->warn('Health check issues: ' . $failedChecks->join(', '));
        } else {
            $this->info('All health checks passed.');
        }

        return self::SUCCESS;
    }

    // ── Individual probes ────────────────────────────────────────

    private function checkDatabase(AlertingService $alerting): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($latencyMs > self::MAX_DB_LATENCY_MS) {
                $alerting->warning('db_slow', "Database latency {$latencyMs}ms exceeds {self::MAX_DB_LATENCY_MS}ms threshold", [
                    'latency_ms' => $latencyMs,
                ]);
                return ['status' => 'degraded', 'latency_ms' => $latencyMs];
            }

            return ['status' => 'ok', 'latency_ms' => $latencyMs];
        } catch (\Throwable $e) {
            $alerting->critical('db_down', 'Database connection failed: ' . $e->getMessage());
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private function checkQueue(AlertingService $alerting): array
    {
        try {
            $pendingCount = DB::table('jobs')->count();

            if ($pendingCount > self::MAX_QUEUE_DEPTH) {
                $alerting->high('queue_backlog', "Queue backlog: {$pendingCount} pending jobs", [
                    'pending_jobs' => $pendingCount,
                    'threshold'    => self::MAX_QUEUE_DEPTH,
                ]);
                return ['status' => 'backlog', 'pending' => $pendingCount];
            }

            return ['status' => 'ok', 'pending' => $pendingCount];
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    private function checkFailedJobs(AlertingService $alerting): array
    {
        try {
            $recentFailed = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();

            if ($recentFailed > self::MAX_FAILED_JOBS) {
                $lastErrors = DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subHour())
                    ->orderByDesc('failed_at')
                    ->take(3)
                    ->pluck('exception')
                    ->map(fn ($e) => mb_substr($e, 0, 200))
                    ->toArray();

                $alerting->high('failed_jobs_spike', "{$recentFailed} failed jobs in the last hour", [
                    'count'         => $recentFailed,
                    'threshold'     => self::MAX_FAILED_JOBS,
                    'recent_errors' => $lastErrors,
                ]);
                return ['status' => 'elevated', 'recent_failed' => $recentFailed];
            }

            return ['status' => 'ok', 'recent_failed' => $recentFailed];
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'error' => $e->getMessage()];
        }
    }

    private function checkDisk(AlertingService $alerting): array
    {
        $storagePath = storage_path();
        $freeBytes = disk_free_space($storagePath);
        $freeGb = round($freeBytes / (1024 ** 3), 2);

        if ($freeGb < self::MIN_DISK_FREE_GB) {
            $alerting->critical('disk_space_low', "Only {$freeGb}GB free on storage volume", [
                'free_gb'   => $freeGb,
                'threshold' => self::MIN_DISK_FREE_GB,
                'path'      => $storagePath,
            ]);
            return ['status' => 'critical', 'free_gb' => $freeGb];
        }

        return ['status' => 'ok', 'free_gb' => $freeGb];
    }

    private function checkCache(AlertingService $alerting): array
    {
        try {
            $testKey = 'health:cache_test_' . uniqid();
            $start = microtime(true);
            Cache::put($testKey, 'ok', 10);
            $value = Cache::get($testKey);
            Cache::forget($testKey);
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if ($value !== 'ok') {
                $alerting->high('cache_inconsistent', 'Cache write-read mismatch');
                return ['status' => 'inconsistent', 'latency_ms' => $latencyMs];
            }

            if ($latencyMs > self::MAX_CACHE_LATENCY_MS) {
                $alerting->warning('cache_slow', "Cache latency {$latencyMs}ms exceeds threshold", [
                    'latency_ms' => $latencyMs,
                ]);
                return ['status' => 'degraded', 'latency_ms' => $latencyMs];
            }

            return ['status' => 'ok', 'latency_ms' => $latencyMs];
        } catch (\Throwable $e) {
            $alerting->critical('cache_down', 'Cache is unavailable: ' . $e->getMessage());
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private function checkHttpMetrics(AlertingService $alerting): array
    {
        $allMetrics = HttpClientFactory::getAllMetrics();
        $issues = [];

        foreach ($allMetrics as $service => $metrics) {
            if (($metrics['total_requests'] ?? 0) < 5) {
                continue; // Not enough data to draw conclusions
            }

            $errorRate = ($metrics['failures'] ?? 0) / $metrics['total_requests'];

            if ($errorRate > self::MAX_HTTP_ERROR_RATE) {
                $alerting->high(
                    "http_failures_{$service}",
                    "External service '{$service}' has " . round($errorRate * 100) . "% error rate",
                    [
                        'service'        => $service,
                        'error_rate'     => round($errorRate * 100, 1) . '%',
                        'total_requests' => $metrics['total_requests'],
                        'failures'       => $metrics['failures'],
                    ]
                );
                $issues[$service] = round($errorRate * 100, 1) . '% errors';
            }
        }

        return [
            'status' => empty($issues) ? 'ok' : 'degraded',
            'services_checked' => count($allMetrics),
            'issues' => $issues,
        ];
    }
}
