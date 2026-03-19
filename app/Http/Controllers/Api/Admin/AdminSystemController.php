<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemMonitoringService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemController extends Controller
{
    use HandlesApiErrors;

    public function health(SystemMonitoringService $monitoring): JsonResponse
    {
        return $this->handleApiAction(function () use ($monitoring) {
            return response()->json([
                'success' => true,
                'data' => $monitoring->getSystemHealth(),
            ]);
        }, 'Failed to load system health.');
    }

    public function tests(SystemMonitoringService $monitoring): JsonResponse
    {
        return $this->handleApiAction(function () use ($monitoring) {
            return response()->json([
                'success' => true,
                'data' => $monitoring->runHealthTests(),
            ]);
        }, 'Failed to run system tests.');
    }

    public function action(Request $request, SystemMonitoringService $monitoring): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $monitoring) {
            $validated = $request->validate([
                'command' => 'required|string|in:queue:restart,cache:clear,optimize:clear,backup:run-db',
            ]);

            $result = $monitoring->executeCommand($validated['command']);
            $status = $result['success'] ? 200 : 422;

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result,
            ], $status);
        }, 'Failed to execute system action.');
    }
}
