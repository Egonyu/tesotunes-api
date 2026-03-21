<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiUsageHourly;
use App\Services\ApiAnalyticsService;
use App\Traits\HandlesApiErrors;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiAnalyticsController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        protected ApiAnalyticsService $analytics,
    ) {}

    /**
     * GET /api/admin/analytics/api-usage
     *
     * Dashboard overview: totals, top endpoints, slowest, errors, over-time chart.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $period = $request->query('range', $request->query('period', '7d'));
            [$start, $end] = $this->analytics->resolvePeriod($period);

            $hourly = ApiUsageHourly::query()->forPeriod($start, $end);
            $totalRequests = (int) (clone $hourly)->sum('total_requests');
            $totalErrors = (int) ((clone $hourly)->sum('client_error_count') + (clone $hourly)->sum('server_error_count'));

            $byEndpoint = ApiUsageHourly::query()
                ->select([
                    'endpoint',
                    DB::raw('SUM(total_requests) as count'),
                    DB::raw('ROUND(AVG(avg_response_ms)) as avg_ms'),
                    DB::raw('SUM(client_error_count + server_error_count) as error_count'),
                ])
                ->forPeriod($start, $end)
                ->groupBy('endpoint')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'endpoint' => (string) $row->endpoint,
                    'count' => (int) $row->count,
                    'avg_ms' => (int) $row->avg_ms,
                    'error_count' => (int) $row->error_count,
                ])
                ->values();

            $today = Carbon::today();
            $byHour = ApiUsageHourly::query()
                ->select('hour', DB::raw('SUM(total_requests) as count'))
                ->whereDate('date', $today)
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->keyBy('hour');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_requests' => $totalRequests,
                    'requests_today' => (int) ApiUsageHourly::query()
                        ->whereDate('date', $today)
                        ->sum('total_requests'),
                    'avg_response_ms' => (int) round((float) ((clone $hourly)->avg('avg_response_ms') ?? 0)),
                    'error_rate' => $totalRequests > 0 ? round(($totalErrors / $totalRequests) * 100, 2) : 0,
                    'by_endpoint' => $byEndpoint,
                    'by_hour' => collect(range(0, 23))->map(fn (int $hour) => [
                        'hour' => $hour,
                        'count' => (int) ($byHour->get($hour)?->count ?? 0),
                    ])->all(),
                ],
            ]);
        });
    }

    /**
     * GET /api/admin/analytics/api-usage/top-users
     *
     * Top API consumers by request count.
     */
    public function topUsers(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $period = $request->query('range', $request->query('period', '7d'));
            $limit = min((int) $request->query('limit', 20), 100);

            return response()->json([
                'success' => true,
                'data' => $this->analytics->getTopUsers($period, $limit),
            ]);
        });
    }
}
