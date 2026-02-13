<?php

namespace App\Http\Controllers\Backend\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function overview()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'users' => 100,
                'songs' => 200,
                'credits' => 5000
            ]
        ]);
    }

    public function users()
    {
        return response()->json([
            'success' => true,
            'data' => []
        ]);
    }

    public function credits()
    {
        return response()->json([
            'success' => true,
            'data' => []
        ]);
    }

    public function music()
    {
        return response()->json([
            'success' => true,
            'data' => []
        ]);
    }
}