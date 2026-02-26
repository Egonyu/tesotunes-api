<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loyalty\JoinLoyaltyCardRequest;
use App\Http\Resources\Loyalty\LoyaltyCardResource;
use App\Http\Resources\Loyalty\LoyaltyRewardResource;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyReward;
use App\Services\Loyalty\MembershipService;
use App\Services\Loyalty\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoyaltyController extends Controller
{
    public function __construct(
        protected MembershipService $membershipService,
        protected RewardService $rewardService,
    ) {}

    /**
     * GET /api/loyalty-cards  — browse all active loyalty cards (public).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $cards = LoyaltyCard::active()
            ->published()
            ->with('artist')
            ->withCount('members')
            ->when($request->filled('artist_id'), fn ($q) => $q->where('artist_id', $request->artist_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('sort'), function ($q) use ($request) {
                return match ($request->sort) {
                    'popular'    => $q->orderByDesc('total_members'),
                    'newest'     => $q->latest(),
                    'price_asc'  => $q->orderByRaw("JSON_EXTRACT(tiers, '$.bronze.price_monthly') ASC"),
                    'price_desc' => $q->orderByRaw("JSON_EXTRACT(tiers, '$.bronze.price_monthly') DESC"),
                    default      => $q->latest(),
                };
            }, fn ($q) => $q->latest())
            ->paginate(min((int) $request->get('per_page', 12), 50));

        return LoyaltyCardResource::collection($cards);
    }

    /**
     * GET /api/loyalty-cards/{slug}  — loyalty card detail (public).
     */
    public function show(string $slug): JsonResponse
    {
        $card = LoyaltyCard::where('slug', $slug)
            ->active()
            ->with(['artist', 'rewards' => fn ($q) => $q->active()])
            ->withCount('members')
            ->firstOrFail();

        return response()->json([
            'data' => new LoyaltyCardResource($card),
        ]);
    }

    /**
     * POST /api/loyalty-cards/{slug}/join  — subscribe to a loyalty card.
     */
    public function join(JoinLoyaltyCardRequest $request, string $slug): JsonResponse
    {
        $card = LoyaltyCard::where('slug', $slug)->active()->firstOrFail();

        try {
            $member = $this->membershipService->subscribe(
                user: $request->user(),
                card: $card,
                tier: $request->tier,
                subscriptionType: $request->subscription_type,
                paymentMethod: $request->payment_method,
                paymentTransactionId: null, // will be filled after payment callback
            );

            return response()->json([
                'message'    => 'Welcome! Your membership is now active.',
                'membership' => [
                    'id'                => $member->id,
                    'tier'              => $member->tier,
                    'status'            => $member->status,
                    'expires_at'        => $member->expires_at->toIso8601String(),
                    'subscription_type' => $member->subscription_type,
                    'price_paid'        => $member->price_paid,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/loyalty-cards/{loyaltyCard}/rewards  — rewards for my tier.
     */
    public function availableRewards(Request $request, string $loyaltyCard): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();
        $card = LoyaltyCard::where('slug', $loyaltyCard)->firstOrFail();

        $membership = $user->loyaltyCardMemberships()
            ->where('loyalty_card_id', $card->id)
            ->active()
            ->first();

        if (!$membership) {
            return response()->json([
                'message' => 'You do not have an active membership for this loyalty card.',
            ], 403);
        }

        $rewards = $this->rewardService->getAvailableRewards($membership);

        return LoyaltyRewardResource::collection($rewards);
    }

    /**
     * POST /api/loyalty-cards/{loyaltyCard}/rewards/{reward}/redeem
     */
    public function redeemReward(Request $request, string $loyaltyCard, int $reward): JsonResponse
    {
        $rewardModel = LoyaltyReward::findOrFail($reward);

        try {
            $redemption = $this->rewardService->redeemReward($request->user(), $rewardModel);

            return response()->json([
                'message'    => 'Reward redeemed successfully!',
                'redemption' => [
                    'id'          => $redemption->id,
                    'status'      => $redemption->status,
                    'content_url' => $rewardModel->type === 'content' ? $rewardModel->content_url : null,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
