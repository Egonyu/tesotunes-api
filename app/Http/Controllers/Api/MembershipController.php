<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Loyalty\LoyaltyCardMemberResource;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Services\Loyalty\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MembershipController extends Controller
{
    public function __construct(
        protected MembershipService $membershipService,
    ) {}

    /**
     * GET /api/my/memberships
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $memberships = LoyaltyCardMember::where('user_id', $request->user()->id)
            ->with('loyaltyCard.artist')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('joined_at')
            ->paginate(min((int) $request->get('per_page', 10), 50));

        return LoyaltyCardMemberResource::collection($memberships);
    }

    /**
     * GET /api/my/memberships/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $membership = LoyaltyCardMember::where('user_id', $request->user()->id)
            ->with(['loyaltyCard.artist', 'loyaltyCard.rewards' => fn ($q) => $q->active()])
            ->findOrFail($id);

        return response()->json([
            'data' => new LoyaltyCardMemberResource($membership),
        ]);
    }

    /**
     * PATCH /api/my/memberships/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $membership = LoyaltyCardMember::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'auto_renew' => ['sometimes', 'boolean'],
            'upgrade_tier' => ['sometimes', 'string', 'in:bronze,silver,gold,platinum'],
        ]);

        if (isset($validated['auto_renew'])) {
            $membership->update(['auto_renew' => $validated['auto_renew']]);
        }

        if (isset($validated['upgrade_tier'])) {
            try {
                $membership = $this->membershipService->changeTier($membership, $validated['upgrade_tier']);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json([
            'message' => 'Membership updated.',
            'data' => new LoyaltyCardMemberResource($membership->fresh()->load('loyaltyCard')),
        ]);
    }

    /**
     * POST /api/my/memberships/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $membership = LoyaltyCardMember::where('user_id', $request->user()->id)
            ->findOrFail($id);

        try {
            $membership = $this->membershipService->cancel($membership);

            return response()->json([
                'message' => 'Membership cancelled. It remains active until the expiry date.',
                'expires_at' => $membership->expires_at?->toIso8601String(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/my/memberships/{id}/renew
     */
    public function renew(Request $request, int $id): JsonResponse
    {
        $membership = LoyaltyCardMember::where('user_id', $request->user()->id)
            ->findOrFail($id);

        try {
            $membership = $this->membershipService->renew($membership);

            return response()->json([
                'message' => 'Membership renewed successfully.',
                'data' => new LoyaltyCardMemberResource($membership->load('loyaltyCard')),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
