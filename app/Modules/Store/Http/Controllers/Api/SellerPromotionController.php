<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerPromotionController extends Controller
{
    /**
     * List the seller's promotions.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'total' => 0,
                'per_page' => 20,
                'last_page' => 1,
            ],
        ]);
    }

    /**
     * Create a new seller promotion.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'data' => null,
            'message' => 'Promotion created successfully.',
        ], 201);
    }

    /**
     * Update a seller promotion.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        return response()->json([
            'data' => $product,
            'message' => 'Promotion updated successfully.',
        ]);
    }

    /**
     * Delete a seller promotion.
     */
    public function destroy(Product $product): JsonResponse
    {
        return response()->json([
            'message' => 'Promotion deleted successfully.',
        ]);
    }

    /**
     * List pending verifications for the seller.
     */
    public function pendingVerifications(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [],
            'meta' => [
                'current_page' => 1,
                'total' => 0,
                'per_page' => 20,
                'last_page' => 1,
            ],
        ]);
    }

    /**
     * Verify completion of an order item.
     */
    public function verifyCompletion(Request $request, OrderItem $orderItem): JsonResponse
    {
        return response()->json([
            'message' => 'Completion verified successfully.',
        ]);
    }

    /**
     * Get seller promotion statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'total_promotions' => 0,
                'active_promotions' => 0,
                'total_orders' => 0,
                'pending_verifications' => 0,
                'total_revenue' => 0,
            ],
        ]);
    }
}
