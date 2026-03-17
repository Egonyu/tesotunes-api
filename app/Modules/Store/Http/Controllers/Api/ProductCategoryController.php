<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\ProductCategory;
use Illuminate\Http\JsonResponse;

class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ProductCategory::query()
            ->active()
            ->ordered()
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json([
            'data' => $categories,
        ]);
    }
}
