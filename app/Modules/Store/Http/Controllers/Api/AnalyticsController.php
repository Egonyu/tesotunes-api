<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics API Controller
 */
class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService
    ) {}

    /**
     * Get store dashboard analytics
     */
    public function dashboard(Request $request, Store $store): JsonResponse
    {
        $this->authorize('viewStatistics', $store);

        $period = $request->get('period', '30days');
        $data = $this->analyticsService->getStoreDashboard($store, $period);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get real-time metrics
     */
    public function realtime(Request $request, Store $store): JsonResponse
    {
        $this->authorize('viewStatistics', $store);

        $metrics = $this->analyticsService->getRealTimeMetrics($store);

        return response()->json([
            'data' => $metrics,
        ]);
    }

    /**
     * Export analytics data
     */
    public function export(Request $request, Store $store): JsonResponse
    {
        $this->authorize('viewStatistics', $store);

        $period = $request->get('period', '30days');
        $data = $this->analyticsService->exportData($store, $period);

        return response()->json([
            'data' => $data,
        ]);
    }
}
