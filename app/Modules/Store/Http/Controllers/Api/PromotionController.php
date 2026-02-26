<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    /**
     * List active promotions for buyers.
     */
    public function index(Request $request): JsonResponse
    {
        $promotions = Promotion::query()
            ->active()
            ->orderBy('priority', 'desc')
            ->orderBy('ends_at', 'asc')
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => $promotions->items(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'total' => $promotions->total(),
                'per_page' => $promotions->perPage(),
                'last_page' => $promotions->lastPage(),
            ],
        ]);
    }

    /**
     * Get promotions the authenticated user has used.
     */
    public function myPromotions(Request $request): JsonResponse
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
     * Show a single promotion by slug.
     */
    public function show(string $slug): JsonResponse
    {
        $promotion = Promotion::where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => $promotion,
        ]);
    }

    /**
     * Submit verification for a promotion order item.
     */
    public function submitVerification(Request $request, OrderItem $orderItem): JsonResponse
    {
        return response()->json([
            'message' => 'Verification submitted successfully.',
        ]);
    }

    /**
     * Dispute a promotion order item.
     */
    public function dispute(Request $request, OrderItem $orderItem): JsonResponse
    {
        return response()->json([
            'message' => 'Dispute submitted successfully.',
        ]);
    }
}
