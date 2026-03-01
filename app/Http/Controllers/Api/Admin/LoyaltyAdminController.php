<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Loyalty\LoyaltyCardResource;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyRewardRedemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyAdminController extends Controller
{
    /**
     * GET /api/admin/loyalty/cards
     */
    public function cards(Request $request): JsonResponse
    {
        $cards = LoyaltyCard::with('artist')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json(LoyaltyCardResource::collection($cards)->response()->getData());
    }

    /**
     * GET /api/admin/loyalty/cards/{loyaltyCard}
     */
    public function showCard(int $loyaltyCard): JsonResponse
    {
        $card = LoyaltyCard::findOrFail($loyaltyCard);
        $card->load(['artist', 'rewards', 'members' => fn ($q) => $q->limit(50)]);

        return response()->json(['data' => new LoyaltyCardResource($card)]);
    }

    /**
     * POST /api/admin/loyalty/cards/{loyaltyCard}/approve
     */
    public function approve(int $loyaltyCard): JsonResponse
    {
        $card = LoyaltyCard::findOrFail($loyaltyCard);

        $card->update([
            'status' => 'active',
            'published_at' => $card->published_at ?? now(),
        ]);

        return response()->json([
            'message' => "Loyalty card '{$card->name}' approved and active.",
            'data' => new LoyaltyCardResource($card->fresh()),
        ]);
    }

    /**
     * POST /api/admin/loyalty/cards/{loyaltyCard}/suspend
     */
    public function suspend(Request $request, int $loyaltyCard): JsonResponse
    {
        $card = LoyaltyCard::findOrFail($loyaltyCard);

        $validated = $request->validate([
            'reason' => ['sometimes', 'string', 'max:500'],
        ]);

        $card->update(['status' => 'suspended']);

        return response()->json([
            'message' => "Loyalty card '{$card->name}' suspended.",
            'data' => new LoyaltyCardResource($card->fresh()),
        ]);
    }

    /**
     * GET /api/admin/loyalty/analytics
     */
    public function analytics(): JsonResponse
    {
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
    }
}
