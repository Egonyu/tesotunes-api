<?php

namespace App\Services;

use App\Models\ApiUsageHourly;
use App\Models\ApiUsageLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApiAnalyticsService
{
    /**
     * Overview dashboard stats for a date range.
     */
    public function getDashboard(string $period = '7d'): array
    {
        [$start, $end] = $this->parsePeriod($period);

        $hourly = ApiUsageHourly::forPeriod($start, $end);

        return [
            'period' => $period,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'totals' => [
                'requests' => (int) (clone $hourly)->sum('total_requests'),
                'success' => (int) (clone $hourly)->sum('success_count'),
                'client_errors' => (int) (clone $hourly)->sum('client_error_count'),
                'server_errors' => (int) (clone $hourly)->sum('server_error_count'),
                'avg_response_ms' => (int) (clone $hourly)->avg('avg_response_ms'),
            ],
            'top_endpoints' => $this->getTopEndpoints($start, $end),
            'slowest_endpoints' => $this->getSlowestEndpoints($start, $end),
            'error_endpoints' => $this->getErrorEndpoints($start, $end),
            'requests_over_time' => $this->getRequestsOverTime($start, $end),
        ];
    }

    /**
     * Top endpoints by request count.
     */
    public function getTopEndpoints(Carbon $start, Carbon $end, int $limit = 20): array
    {
        return ApiUsageHourly::select([
            'endpoint',
            'method',
            DB::raw('SUM(total_requests) as total'),
            DB::raw('SUM(unique_users) as unique_users'),
            DB::raw('ROUND(AVG(avg_response_ms)) as avg_ms'),
        ])
        ->forPeriod($start, $end)
        ->groupBy('endpoint', 'method')
        ->orderByDesc('total')
        ->limit($limit)
        ->get()
        ->toArray();
    }

    /**
     * Slowest endpoints by average response time.
     */
    public function getSlowestEndpoints(Carbon $start, Carbon $end, int $limit = 10): array
    {
        return ApiUsageHourly::select([
            'endpoint',
            'method',
            DB::raw('ROUND(AVG(avg_response_ms)) as avg_ms'),
            DB::raw('MAX(max_response_ms) as max_ms'),
            DB::raw('SUM(total_requests) as total'),
        ])
        ->forPeriod($start, $end)
        ->groupBy('endpoint', 'method')
        ->having(DB::raw('SUM(total_requests)'), '>', 10)
        ->orderByDesc('avg_ms')
        ->limit($limit)
        ->get()
        ->toArray();
    }

    /**
     * Endpoints with highest error rates.
     */
    public function getErrorEndpoints(Carbon $start, Carbon $end, int $limit = 10): array
    {
        return ApiUsageHourly::select([
            'endpoint',
            'method',
            DB::raw('SUM(client_error_count + server_error_count) as errors'),
            DB::raw('SUM(total_requests) as total'),
            DB::raw('ROUND(SUM(client_error_count + server_error_count) / SUM(total_requests) * 100, 1) as error_rate'),
        ])
        ->forPeriod($start, $end)
        ->groupBy('endpoint', 'method')
        ->having('errors', '>', 0)
        ->orderByDesc('error_rate')
        ->limit($limit)
        ->get()
        ->toArray();
    }

    /**
     * Request volume over time (daily buckets).
     */
    public function getRequestsOverTime(Carbon $start, Carbon $end): array
    {
        return ApiUsageHourly::select([
            'date',
            DB::raw('SUM(total_requests) as requests'),
            DB::raw('SUM(success_count) as success'),
            DB::raw('SUM(client_error_count) as client_errors'),
            DB::raw('SUM(server_error_count) as server_errors'),
            DB::raw('ROUND(AVG(avg_response_ms)) as avg_ms'),
        ])
        ->forPeriod($start, $end)
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->toArray();
    }

    /**
     * Per-user usage stats (top consumers).
     */
    public function getTopUsers(string $period = '7d', int $limit = 20): array
    {
        [$start, $end] = $this->parsePeriod($period);

        return ApiUsageLog::select([
            'user_id',
            DB::raw('COUNT(*) as total_requests'),
            DB::raw('ROUND(AVG(response_time_ms)) as avg_ms'),
            DB::raw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors'),
        ])
        ->whereNotNull('user_id')
        ->since($start)
        ->where('requested_at', '<=', $end)
        ->groupBy('user_id')
        ->orderByDesc('total_requests')
        ->limit($limit)
        ->get()
        ->toArray();
    }

    protected function parsePeriod(string $period): array
    {
        $end = Carbon::now();

        return match ($period) {
            '24h' => [Carbon::now()->subDay(), $end],
            '7d' => [Carbon::now()->subDays(7), $end],
            '30d' => [Carbon::now()->subDays(30), $end],
            '90d' => [Carbon::now()->subDays(90), $end],
            default => [Carbon::now()->subDays(7), $end],
        };
    }
}
