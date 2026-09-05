<?php

namespace App\Modules\Promotions\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Services\PromoterOnboardingService;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoterOnboardingController extends Controller
{
    public function __construct(private readonly PromoterOnboardingService $onboardingService) {}

    /**
     * Browse the promoter marketplace — public, unauthenticated.
     */
    public function discover(Request $request): JsonResponse
    {
        $query = PromoterProfile::with('user:id,username,avatar')
            ->active()
            ->orderByDesc('average_rating')
            ->orderByDesc('total_completed_orders');

        if ($request->filled('tier')) {
            $query->byTier($request->string('tier'));
        }

        if ($request->filled('platform')) {
            $query->whereJsonContains('platforms', $request->string('platform')->toString());
        }

        if ($request->filled('niche')) {
            $query->whereJsonContains('niches', $request->string('niche')->toString());
        }

        if ($request->filled('region')) {
            $query->whereJsonContains('audience_regions', $request->string('region')->toString());
        }

        $profiles = $query->paginate($request->integer('per_page', 20));

        return response()->json($profiles);
    }

    /**
     * View a single promoter profile — public.
     */
    public function show(string $slug): JsonResponse
    {
        $profile = PromoterProfile::with(['user:id,username,name,avatar', 'store:id,banner'])
            ->where('slug', $slug)
            ->firstOrFail();

        $this->authorize('view', $profile);

        return response()->json(['data' => $this->serializePublicProfile($profile)]);
    }

    /**
     * A promoter's public storefront.
     *
     * The raw model was returned here, which is not what a storefront needs:
     * no listings — the whole point of the page — a relative avatar path
     * rather than a URL, average_rating as a decimal string, and the internal
     * verified_by / deleted_at / metadata columns exposed to anyone.
     *
     * @return array<string, mixed>
     */
    private function serializePublicProfile(PromoterProfile $profile): array
    {
        $listings = Product::query()
            ->promotion()
            ->active()
            ->where('store_id', $profile->store_id)
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas(
                    'order',
                    fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)
                ),
            ])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->get();

        return [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'display_name' => $profile->display_name,
            'username' => $profile->user?->username,
            'avatar_url' => StorageHelper::avatarUrl($profile->user?->avatar, $profile->display_name ?? 'Promoter'),
            'banner_url' => $profile->store?->banner,
            'location' => data_get($profile->metadata ?? [], 'location'),
            'bio' => $profile->bio,
            'tier' => $profile->tier,
            'is_verified' => (bool) $profile->is_verified,
            'platforms' => array_values((array) ($profile->platforms ?? [])),
            'niches' => array_values((array) ($profile->niches ?? [])),
            'audience_regions' => array_values((array) ($profile->audience_regions ?? [])),
            'audience_summary' => $profile->audience_summary,
            'response_time_hours' => $profile->response_time_hours !== null ? (int) $profile->response_time_hours : null,
            'proof_points' => array_values(array_filter((array) ($profile->proof_points ?? []))),
            'campaign_highlights' => array_values(array_filter((array) ($profile->campaign_highlights ?? []))),
            'portfolio_items' => array_values((array) ($profile->portfolio_items ?? [])),
            'social_links' => (object) array_filter((array) ($profile->social_links ?? [])),
            'average_rating' => (float) ($profile->average_rating ?? 0),
            'review_count' => (int) ($profile->review_count ?? 0),
            'completed_orders' => (int) ($profile->total_completed_orders ?? 0),
            'onboarded_at' => optional($profile->onboarded_at)->toIso8601String(),
            // Listings use the same shape the browse endpoint returns, so the
            // storefront and the marketplace render from one contract.
            'promotions' => $listings->map(fn (Product $listing) => $this->serializeListing($listing, $profile))->values()->all(),
        ];
    }

    /**
     * One promotion listing, in the shape /api/promotions returns.
     *
     * @return array<string, mixed>
     */
    private function serializeListing(Product $listing, PromoterProfile $profile): array
    {
        $metadata = is_array($listing->metadata ?? null) ? $listing->metadata : [];
        $rating = (float) ($listing->average_rating ?? 0);

        return [
            'id' => $listing->id,
            'slug' => $listing->slug,
            'title' => $listing->name,
            'short_description' => (string) ($listing->short_description ?? ''),
            'type' => (string) ($listing->promotion_type ?? 'social_media_mention'),
            'platform' => (string) ($listing->promotion_platform ?? 'other'),
            'price_credits' => (int) ($listing->price_credits ?? 0),
            'price_ugx' => (float) ($listing->price_ugx ?? 0),
            'accepts_credits' => (bool) ($listing->allow_credit_payment || $listing->accepts_credits),
            'accepts_ugx' => (float) ($listing->price_ugx ?? 0) > 0,
            'accepts_hybrid' => (bool) $listing->allow_hybrid_payment,
            'estimated_reach' => (int) ($listing->estimated_reach ?? 0),
            'audience_niches' => array_values(array_filter((array) data_get($metadata, 'audience_niches', []))),
            'audience_regions' => array_values(array_filter((array) data_get($metadata, 'audience_regions', []))),
            'content_formats' => array_values(array_filter((array) data_get($metadata, 'content_formats', []))),
            'delivery_days_min' => (int) ($listing->delivery_days_min ?? 1),
            'delivery_days_max' => (int) ($listing->delivery_days_max ?? 7),
            'platform_specifics' => data_get($metadata, 'platform_specifics', []),
            'rating_average' => $rating,
            'rating_count' => (int) ($listing->review_count ?? 0),
            'total_orders' => (int) ($listing->total_orders ?? 0),
            'completed_orders' => (int) ($listing->completed_orders ?? 0),
            'is_featured' => (bool) $listing->is_featured,
            'is_top_rated' => $rating >= 4.5,
            'featured_image_url' => $listing->featured_image_url,
            'status' => Product::promotionStatusForWire($listing->status),
            'created_at' => optional($listing->created_at)->toIso8601String(),
            'promoter' => [
                'id' => $profile->user_id,
                'name' => $profile->display_name,
                'username' => $profile->slug,
                'avatar_url' => StorageHelper::avatarUrl($profile->user?->avatar, $profile->display_name ?? 'Promoter'),
                'is_verified' => (bool) $profile->is_verified,
                'follower_count' => 0,
            ],
        ];
    }

    /**
     * Onboard the authenticated user as a promoter.
     * No artist role required — any user can onboard.
     */
    public function onboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => 'sometimes|string|max:200',
            'bio' => 'nullable|string|max:2000',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:50',
            'niches' => 'nullable|array',
            'niches.*' => 'string|max:50',
            'audience_regions' => 'nullable|array',
            'audience_regions.*' => 'string|max:100',
            'audience_summary' => 'nullable|string|max:500',
            'social_links' => 'nullable|array',
            'response_time_hours' => 'nullable|integer|min:1|max:168',
        ]);

        try {
            $profile = $this->onboardingService->onboard($request->user(), $data);

            $alreadyExisted = $profile->wasRecentlyCreated === false;

            return response()->json([
                'data' => $profile,
                'message' => $alreadyExisted ? 'You are already a promoter.' : 'Welcome! Your promoter profile is ready.',
            ], $alreadyExisted ? 200 : 201);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Onboarding failed. Please try again.'], 500);
        }
    }

    /**
     * Get the authenticated user's promoter profile.
     */
    public function myProfile(Request $request): JsonResponse
    {
        $profile = PromoterProfile::where('user_id', $request->user()->id)->first();

        if (! $profile) {
            return response()->json(['data' => null, 'is_promoter' => false]);
        }

        return response()->json(['data' => $profile, 'is_promoter' => true]);
    }

    /**
     * Update the authenticated user's promoter profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $profile = PromoterProfile::where('user_id', $request->user()->id)->firstOrFail();

        $this->authorize('update', $profile);

        $data = $request->validate([
            'display_name' => 'sometimes|string|max:200',
            'bio' => 'nullable|string|max:2000',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:50',
            'niches' => 'nullable|array',
            'niches.*' => 'string|max:50',
            'audience_regions' => 'nullable|array',
            'audience_regions.*' => 'string|max:100',
            'audience_summary' => 'nullable|string|max:500',
            'social_links' => 'nullable|array',
            'portfolio_items' => 'nullable|array',
            'proof_points' => 'nullable|array',
            'campaign_highlights' => 'nullable|array',
            'response_time_hours' => 'nullable|integer|min:1|max:168',
        ]);

        $updated = $this->onboardingService->updateProfile($profile, $data);

        return response()->json(['data' => $updated]);
    }
}
