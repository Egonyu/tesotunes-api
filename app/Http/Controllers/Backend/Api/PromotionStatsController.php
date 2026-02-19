<?php

namespace App\Http\Controllers\Backend\Api;

use App\Http\Controllers\Controller;

class PromotionStatsController extends Controller
{
    public function active()
    {
        return response()->json([
            'success' => true,
            'data' => [],
        ]);
    }
}
