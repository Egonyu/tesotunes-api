<?php

namespace App\Console\Commands;

use App\Models\ApiUsageHourly;
use App\Models\ApiUsageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw API usage logs into hourly rollup rows.
 *
 * Run hourly via scheduler. Processes the previous hour's data and
 * optionally prunes raw logs older than a configurable retention period.
 */
class AggregateApiUsage extends Command
{
    protected $signature = 'api-usage:aggregate
        {--prune-days=30 : Delete raw logs older than this many days}
        {--hour= : Specific hour to aggregate (Y-m-d-H format)}';

    protected $description = 'Aggregate API usage logs into hourly rollups';

    public function handle(): int
    {
        $hourOption = $this->option('hour');

        if ($hourOption) {
            $date = substr($hourOption, 0, 10);
            $hour = (int) substr($hourOption, 11, 2);
            $start = \Carbon\Carbon::parse("{$date} {$hour}:00:00");
        } else {
            $start = now()->subHour()->startOfHour();
        }

        $end = $start->copy()->addHour();
        $date = $start->toDateString();
        $hour = $start->hour;

        $this->info("Aggregating API usage for {$date} hour {$hour}...");

        // Group raw logs by endpoint + method for the target hour
        $aggregated = ApiUsageLog::select([
            'endpoint',
            'method',
            DB::raw('COUNT(*) as total_requests'),
            DB::raw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as success_count'),
            DB::raw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as client_error_count'),
            DB::raw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as server_error_count'),
            DB::raw('ROUND(AVG(response_time_ms)) as avg_response_ms'),
            DB::raw('MAX(response_time_ms) as max_response_ms'),
            DB::raw('COUNT(DISTINCT user_id) as unique_users'),
        ])
        ->where('requested_at', '>=', $start)
        ->where('requested_at', '<', $end)
        ->groupBy('endpoint', 'method')
        ->get();

        $count = 0;
        foreach ($aggregated as $row) {
            ApiUsageHourly::updateOrCreate(
                [
                    'endpoint' => $row->endpoint,
                    'method' => $row->method,
                    'date' => $date,
                    'hour' => $hour,
                ],
                [
                    'total_requests' => $row->total_requests,
                    'success_count' => $row->success_count,
                    'client_error_count' => $row->client_error_count,
                    'server_error_count' => $row->server_error_count,
                    'avg_response_ms' => $row->avg_response_ms,
                    'max_response_ms' => $row->max_response_ms,
                    'unique_users' => $row->unique_users,
                ]
            );
            $count++;
        }

        $this->info("Aggregated {$count} endpoint groups.");

        // Prune old raw logs
        $pruneDays = (int) $this->option('prune-days');
        if ($pruneDays > 0) {
            $cutoff = now()->subDays($pruneDays);
            $deleted = ApiUsageLog::where('requested_at', '<', $cutoff)->delete();
            if ($deleted > 0) {
                $this->info("Pruned {$deleted} raw logs older than {$pruneDays} days.");
            }
        }

        return self::SUCCESS;
    }
}
