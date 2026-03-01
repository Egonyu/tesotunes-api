<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdminIndexController extends Controller
{
    /**
     * GET /api/admin — Base admin route
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin API',
            'version' => '1.0',
            'endpoints' => [
                'dashboard' => '/admin/dashboard/stats',
                'users' => '/admin/users',
                'settings' => '/admin/settings',
                'sacco' => '/admin/sacco/stats',
            ],
        ]);
    }
}
