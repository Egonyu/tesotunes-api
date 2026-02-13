<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdTrackingController extends Controller
{
    /**
     * Record an ad impression.
     */
    public function recordImpression(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Impression recorded.',
        ]);
    }

    /**
     * Record an ad click.
     */
    public function recordClick(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Click recorded.',
        ]);
    }
}
