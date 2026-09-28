<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArtistPayout;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminArtistPayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,approved,processing,completed,failed,rejected,cancelled',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ArtistPayout::query()
            ->with(['artist.user:id,name,email', 'requestedBy:id,name,email', 'approvedBy:id,name,email'])
            ->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->paginate($validated['per_page'] ?? 25));
    }

    public function approve(Request $request, ArtistPayout $payout, PayoutService $service): JsonResponse
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:1000']);
        $approval = $service->approvePayout($payout, $request->user(), $validated['notes'] ?? null);

        if (! ($approval['success'] ?? false)) {
            return response()->json(['message' => $approval['message'] ?? 'Payout approval failed.'], 422);
        }

        $result = $service->processPayout($payout->fresh());

        return response()->json([
            'message' => $result['message'] ?? 'Payout approved.',
            'data' => $payout->fresh(['artist.user', 'requestedBy', 'approvedBy']),
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function reject(Request $request, ArtistPayout $payout, PayoutService $service): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);
        $result = $service->rejectPayout($payout, $request->user(), $validated['reason']);

        return response()->json([
            'message' => $result['message'],
            'data' => $payout->fresh(['artist.user', 'requestedBy', 'approvedBy']),
        ]);
    }

    public function retry(ArtistPayout $payout, PayoutService $service): JsonResponse
    {
        $result = $service->retryPayout($payout);

        return response()->json([
            'message' => $result['message'] ?? 'Payout retry completed.',
            'data' => $payout->fresh(['artist.user', 'requestedBy', 'approvedBy']),
        ], ($result['success'] ?? false) ? 200 : 422);
    }
}
