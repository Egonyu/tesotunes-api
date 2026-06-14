<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unified account dashboard for every user — wallet, earnings across all
 * verticals, listening, contributions, capabilities, and recent activity.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * GET /api/dashboard/overview
     */
    public function overview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard->overview($request->user()),
        ]);
    }
}
