<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiAnalyticsService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $period = $request->query('period', '7d');

            return $this->analytics->getDashboard($period);
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
            $period = $request->query('period', '7d');
            $limit = min((int) $request->query('limit', 20), 100);

            return $this->analytics->getTopUsers($period, $limit);
        });
    }
}
