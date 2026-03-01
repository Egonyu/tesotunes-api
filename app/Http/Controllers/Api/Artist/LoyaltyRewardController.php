<?php

namespace App\Http\Controllers\Api\Artist;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loyalty\CreateRewardRequest;
use App\Http\Resources\Loyalty\LoyaltyRewardResource;
use App\Models\Loyalty\LoyaltyCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoyaltyRewardController extends Controller
{
    /**
     * GET /api/artist/loyalty-cards/{loyaltyCard}/rewards
     */
    public function index(Request $request, string $loyaltyCard): AnonymousResourceCollection
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();

        $rewards = $card->rewards()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('tier'), fn ($q) => $q->where('required_tier', $request->tier))
            ->latest()
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return LoyaltyRewardResource::collection($rewards);
    }

    /**
     * POST /api/artist/loyalty-cards/{loyaltyCard}/rewards
     */
    public function store(CreateRewardRequest $request, string $loyaltyCard): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();

        $reward = $card->rewards()->create($request->validated());

        return response()->json([
            'message' => 'Reward created successfully.',
            'data' => new LoyaltyRewardResource($reward),
        ], 201);
    }

    /**
     * PUT /api/artist/loyalty-cards/{loyaltyCard}/rewards/{rewardId}
     */
    public function update(Request $request, string $loyaltyCard, int $rewardId): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();
        $reward = $card->rewards()->findOrFail($rewardId);

        $reward->update($request->only([
            'name', 'description', 'type', 'required_tier',
            'content_type', 'content_url', 'product_id', 'discount_percentage',
            'event_id', 'experience_type', 'points_amount',
            'is_active', 'available_from', 'available_until', 'max_redemptions',
        ]));

        return response()->json([
            'message' => 'Reward updated successfully.',
            'data' => new LoyaltyRewardResource($reward->fresh()),
        ]);
    }

    /**
     * DELETE /api/artist/loyalty-cards/{loyaltyCard}/rewards/{rewardId}
     */
    public function destroy(Request $request, string $loyaltyCard, int $rewardId): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();
        $reward = $card->rewards()->findOrFail($rewardId);
        $reward->delete();

        return response()->json(['message' => 'Reward deleted.']);
    }
}
