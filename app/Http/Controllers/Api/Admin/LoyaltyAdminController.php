<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Loyalty\LoyaltyCardResource;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyRewardRedemption;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyAdminController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/admin/loyalty/cards
     */
    public function cards(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $cards = LoyaltyCard::with('artist')
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.addcslashes($request->search, '%_').'%'))
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->get('per_page', 20), 100));

            return response()->json([
                'success' => true,
                'data' => LoyaltyCardResource::collection($cards),
                'meta' => [
                    'current_page' => $cards->currentPage(),
                    'last_page' => $cards->lastPage(),
                    'per_page' => $cards->perPage(),
                    'total' => $cards->total(),
                ],
            ]);
        }, 'Failed to retrieve loyalty cards.');
    }

    /**
     * GET /api/admin/loyalty/cards/{loyaltyCard}
     */
    public function showCard(int $loyaltyCard): JsonResponse
    {
        return $this->handleApiAction(function () use ($loyaltyCard) {
            $card = LoyaltyCard::findOrFail($loyaltyCard);
            $card->load(['artist', 'rewards', 'members' => fn ($q) => $q->limit(50)]);

            return response()->json(['success' => true, 'data' => new LoyaltyCardResource($card)]);
        }, 'Failed to retrieve loyalty card details.');
    }

    /**
     * POST /api/admin/loyalty/cards/{loyaltyCard}/approve
     */
    public function approve(int $loyaltyCard): JsonResponse
    {
        return $this->handleApiAction(function () use ($loyaltyCard) {
            $card = LoyaltyCard::findOrFail($loyaltyCard);

            $card->update([
                'status' => 'active',
                'published_at' => $card->published_at ?? now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Loyalty card '{$card->name}' approved and active.",
                'data' => new LoyaltyCardResource($card->fresh()),
            ]);
        }, 'Failed to approve loyalty card.');
    }

    /**
     * POST /api/admin/loyalty/cards/{loyaltyCard}/suspend
     */
    public function suspend(Request $request, int $loyaltyCard): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $loyaltyCard) {
            $card = LoyaltyCard::findOrFail($loyaltyCard);

            $validated = $request->validate([
                'reason' => ['sometimes', 'string', 'max:500'],
            ]);

            $card->update(['status' => 'suspended']);

            return response()->json([
                'success' => true,
                'message' => "Loyalty card '{$card->name}' suspended.",
                'data' => new LoyaltyCardResource($card->fresh()),
            ]);
        }, 'Failed to suspend loyalty card.');
    }

    /**
     * GET /api/admin/loyalty/analytics
     */
    public function analytics(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $totalCards = LoyaltyCard::count();
            $activeCards = LoyaltyCard::where('status', 'active')->count();
            $totalMembers = LoyaltyCardMember::count();
            $activeMembers = LoyaltyCardMember::where('status', 'active')->count();
            $totalRedemptions = LoyaltyRewardRedemption::count();

            $membersByTier = LoyaltyCardMember::where('status', 'active')
                ->selectRaw('tier, count(*) as count')
                ->groupBy('tier')
                ->pluck('count', 'tier');

            $topCards = LoyaltyCard::where('status', 'active')
                ->orderByDesc('total_members')
                ->limit(10)
                ->get(['id', 'name', 'total_members', 'artist_id'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'total_members' => $c->total_members,
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_cards' => $totalCards,
                    'active_cards' => $activeCards,
                    'total_members' => $totalMembers,
                    'active_members' => $activeMembers,
                    'total_redemptions' => $totalRedemptions,
                    'members_by_tier' => $membersByTier,
                    'top_cards' => $topCards,
                ],
            ]);
        }, 'Failed to retrieve loyalty analytics.');
    }
}
