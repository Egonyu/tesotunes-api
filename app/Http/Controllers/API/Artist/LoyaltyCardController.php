<?php

namespace App\Http\Controllers\Api\Artist;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loyalty\CreateLoyaltyCardRequest;
use App\Http\Requests\Loyalty\UpdateLoyaltyCardRequest;
use App\Http\Resources\Loyalty\LoyaltyCardResource;
use App\Http\Resources\Loyalty\LoyaltyCardMemberResource;
use App\Models\Loyalty\LoyaltyCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoyaltyCardController extends Controller
{
    /**
     * GET /api/artist/loyalty-cards
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $artist = $request->user()->artist;

        abort_unless($artist, 403, 'You must be an artist to access this resource.');

        $cards = LoyaltyCard::byArtist($artist->id)
            ->withCount('members')
            ->latest()
            ->paginate(min((int) $request->get('per_page', 10), 50));

        return LoyaltyCardResource::collection($cards);
    }

    /**
     * POST /api/artist/loyalty-cards
     */
    public function store(CreateLoyaltyCardRequest $request): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403, 'You must be an artist to access this resource.');

        // Check max cards limit
        $existingCount = LoyaltyCard::byArtist($artist->id)->count();
        $maxCards = config('loyalty.max_cards_per_artist', 5);

        if ($existingCount >= $maxCards) {
            return response()->json([
                'message' => "You can create a maximum of {$maxCards} loyalty cards.",
            ], 422);
        }

        $card = LoyaltyCard::create([
            'artist_id'       => $artist->id,
            'name'            => $request->name,
            'description'     => $request->description,
            'logo_url'        => $request->logo_url,
            'banner_url'      => $request->banner_url,
            'primary_color'   => $request->primary_color,
            'secondary_color' => $request->secondary_color,
            'tiers'           => $request->tiers,
            'allow_monthly'   => $request->boolean('allow_monthly', true),
            'allow_yearly'    => $request->boolean('allow_yearly', true),
            'auto_renew'      => $request->boolean('auto_renew', true),
            'status'          => config('loyalty.requires_admin_approval') ? 'draft' : 'active',
        ]);

        return response()->json([
            'message' => 'Loyalty card created successfully.',
            'data'    => new LoyaltyCardResource($card),
        ], 201);
    }

    /**
     * GET /api/artist/loyalty-cards/{loyaltyCard}
     */
    public function show(Request $request, string $loyaltyCard): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)
            ->withCount('members')
            ->where('slug', $loyaltyCard)
            ->firstOrFail();

        return response()->json([
            'data' => new LoyaltyCardResource($card->load('rewards')),
        ]);
    }

    /**
     * PUT /api/artist/loyalty-cards/{loyaltyCard}
     */
    public function update(UpdateLoyaltyCardRequest $request, string $loyaltyCard): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();
        $card->update($request->validated());

        return response()->json([
            'message' => 'Loyalty card updated successfully.',
            'data'    => new LoyaltyCardResource($card->fresh()),
        ]);
    }

    /**
     * DELETE /api/artist/loyalty-cards/{loyaltyCard}
     */
    public function destroy(Request $request, string $loyaltyCard): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();

        // Archive instead of hard delete if there are active members
        if ($card->members()->active()->exists()) {
            $card->update(['status' => 'archived']);

            return response()->json(['message' => 'Loyalty card archived (has active members).']);
        }

        $card->delete();

        return response()->json(['message' => 'Loyalty card deleted.']);
    }

    /**
     * PATCH /api/artist/loyalty-cards/{loyaltyCard}/publish
     */
    public function publish(Request $request, string $loyaltyCard): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();

        if ($card->status === 'active') {
            return response()->json(['message' => 'Card is already published.'], 422);
        }

        $card->update([
            'status'       => config('loyalty.requires_admin_approval') ? 'draft' : 'active',
            'published_at' => now(),
        ]);

        return response()->json([
            'message' => config('loyalty.requires_admin_approval')
                ? 'Card submitted for review.'
                : 'Card published successfully.',
            'data' => new LoyaltyCardResource($card->fresh()),
        ]);
    }

    /**
     * GET /api/artist/loyalty-cards/{loyaltyCard}/members
     */
    public function members(Request $request, string $loyaltyCard): AnonymousResourceCollection
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();

        $members = $card->members()
            ->with('user')
            ->when($request->filled('tier'), fn ($q) => $q->where('tier', $request->tier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('joined_at')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return LoyaltyCardMemberResource::collection($members);
    }

    /**
     * GET /api/artist/loyalty-cards/{loyaltyCard}/analytics
     */
    public function analytics(Request $request, string $loyaltyCard): JsonResponse
    {
        $artist = $request->user()->artist;
        abort_unless($artist, 403);

        $card = LoyaltyCard::byArtist($artist->id)->where('slug', $loyaltyCard)->firstOrFail();

        $activeMembers = $card->members()->active()->count();
        $totalMembers = $card->members()->count();

        $tierBreakdown = $card->members()
            ->active()
            ->selectRaw('tier, COUNT(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier')
            ->toArray();

        $monthlyRevenue = $card->members()
            ->active()
            ->where('subscription_type', 'monthly')
            ->sum('price_paid');

        $newThisMonth = $card->members()
            ->where('joined_at', '>=', now()->startOfMonth())
            ->count();

        $churned = $card->members()
            ->whereIn('status', ['expired', 'cancelled'])
            ->where('updated_at', '>=', now()->subMonth())
            ->count();

        $churnRate = $totalMembers > 0 ? round($churned / $totalMembers, 4) : 0;

        $renewalRate = $card->members()
            ->where('total_renewals', '>', 0)
            ->count();
        $renewalRate = $activeMembers > 0 ? round($renewalRate / $activeMembers, 4) : 0;

        $avgLtv = $card->members()->avg('lifetime_value') ?? 0;

        return response()->json([
            'data' => [
                'total_members'           => $totalMembers,
                'active_members'          => $activeMembers,
                'new_members_this_month'  => $newThisMonth,
                'churn_rate'              => $churnRate,
                'renewal_rate'            => $renewalRate,
                'monthly_revenue'         => $monthlyRevenue,
                'tier_breakdown'          => $tierBreakdown,
                'average_ltv'             => round($avgLtv, 2),
            ],
        ]);
    }
}
