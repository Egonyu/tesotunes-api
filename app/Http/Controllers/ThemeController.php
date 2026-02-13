<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Theme updated.']);
    }

    public function get(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['theme' => 'dark']]);
    }
}
