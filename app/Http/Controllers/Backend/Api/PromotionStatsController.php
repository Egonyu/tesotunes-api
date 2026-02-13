<?php

namespace App\Http\Controllers\Backend\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PromotionStatsController extends Controller
{
    public function active()
    {
        return response()->json([
            'success' => true,
            'data' => []
        ]);
    }
}