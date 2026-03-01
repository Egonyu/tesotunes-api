<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyTransaction;
use App\Services\Loyalty\LoyaltyPointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyPointsController extends Controller
{
    public function __construct(
        protected LoyaltyPointsService $pointsService,
    ) {}

    /**
     * GET /api/my/loyalty-points
     */
    public function show(Request $request): JsonResponse
    {
        $balance = $this->pointsService->getBalance($request->user());

        return response()->json(['data' => $balance]);
    }

    /**
     * GET /api/my/loyalty-points/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = LoyaltyTransaction::where('user_id', $request->user()->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json($transactions);
    }

    /**
     * POST /api/my/loyalty-points/convert
     */
    public function convert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'points' => ['required', 'integer', 'min:10'],
        ]);

        try {
            $result = $this->pointsService->convertToCredits($request->user(), $validated['points']);

            return response()->json([
                'message' => "Converted {$result['points_spent']} points to {$result['credits_earned']} credits.",
                'points_spent' => $result['points_spent'],
                'credits_earned' => $result['credits_earned'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
