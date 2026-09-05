<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Services\PromoterOnboardingService;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\StoreService;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SellerPromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $storeId = $request->user()?->store?->id;

        if (! $storeId) {
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

        $status = trim((string) $request->input('status', ''));
        $promotions = Product::query()
            ->promotion()
            ->where('store_id', $storeId)
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedGenericReviews as rating_count',
            ])
            ->when($status !== '', fn ($query) => $query->where('status', Product::promotionStatusFromWire($status)))
            ->latest()
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => collect($promotions->items())->map(fn (Product $promotion) => $this->serializePromotion($promotion))->values(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'total' => $promotions->total(),
                'per_page' => $promotions->perPage(),
                'last_page' => $promotions->lastPage(),
                'from' => $promotions->firstItem(),
                'to' => $promotions->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePromotionPayload($request);
        $store = $this->resolveSellerStore($request);

        if ($store instanceof JsonResponse) {
            return $store;
        }

        $promotion = Product::create([
            'store_id' => $store->id,
            'name' => $validated['title'],
            'slug' => $this->generateUniqueSlug($validated['title']),
            'description' => $validated['description'],
            'short_description' => $validated['short_description'],
            'product_type' => Product::TYPE_PROMOTION,
            'status' => Product::STATUS_DRAFT,
            'is_active' => false,
            'is_featured' => false,
            'featured_image' => $validated['featured_image'] ?? null,
            'price_credits' => $validated['price_credits'],
            'price_ugx' => $validated['price_ugx'],
            'allow_credit_payment' => $validated['accepts_credits'],
            'allow_hybrid_payment' => $validated['accepts_hybrid'],
            'accepts_credits' => $validated['accepts_credits'],
            ...$this->promotionColumns($validated),
            'metadata' => $this->buildMetadata($validated, [
                'moderation' => [
                    'status' => 'pending',
                    'submitted_at' => now()->toIso8601String(),
                    'submitted_by' => $request->user()?->id,
                ],
            ]),
        ]);

        $this->logSellerActivity($request, 'promotion_listing_created', $promotion, [
            'promotion_id' => $promotion->id,
            'promotion_slug' => $promotion->slug,
            'store_id' => $store->id,
        ]);

        return response()->json([
            'promotion' => $this->serializePromotion($promotion->loadMissing(['store.user'])),
            'message' => 'Promotion created successfully and submitted for review.',
        ], 201);
    }

    public function show(Request $request, int $promotionId): JsonResponse
    {
        $storeId = $request->user()?->store?->id;
        $promotion = Product::query()
            ->promotion()
            ->where('store_id', $storeId)
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedGenericReviews as rating_count',
            ])
            ->findOrFail($promotionId);

        return response()->json([
            'data' => $this->serializePromotion($promotion),
        ]);
    }

    /**
     * The promoter's own storefront profile.
     *
     * Editable fields come from promoter_profiles — the one table — rather
     * than the stores.metadata.promoter_profile blob this used to read. The
     * two records had drifted into separate systems: onboarding at
     * /become-promoter wrote the table, this editor wrote the blob, and a
     * promoter who used both was editing two different profiles. The counts
     * and ratings below stay derived from the promoter's live listings.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $store = $user?->store;
        $profile = $this->promoterProfileFor($user);
        $promotions = Product::query()
            ->promotion()
            ->where('store_id', $store?->id)
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
            ])
            ->get();

        return response()->json([
            'data' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'username' => $user?->username,
                'avatar_url' => $user?->avatar_url ?? $user?->avatar ?? null,
                'banner_url' => $store?->banner ?? $user?->banner ?? null,
                'bio' => $profile?->bio ?? $store?->description ?? $user?->bio ?? null,
                'location' => $this->formatLocation(
                    $store?->city ?? $user?->city,
                    $store?->country ?? $user?->country,
                    data_get($profile?->metadata ?? [], 'location')
                ),
                'is_verified' => (bool) ($profile?->is_verified ?? $user?->is_verified ?? $store?->is_verified ?? false),
                'follower_count' => (int) ($user?->followers_count ?? 0),
                'total_promotions' => $promotions->count(),
                'active_promotions' => $promotions->where('status', Product::STATUS_ACTIVE)->count(),
                'featured_promotions' => $promotions->where('is_featured', true)->count(),
                'average_rating' => round((float) ($promotions->avg('average_rating') ?? 0), 2),
                'completed_orders' => (int) $promotions->sum('completed_orders'),
                'platforms' => $promotions->pluck('metadata.platform')->filter()->unique()->values()->all(),
                'service_types' => $promotions->pluck('metadata.promotion_type')->filter()->unique()->values()->all(),
                'social_links' => $this->serializePromoterSocialLinks($user, (array) ($profile?->social_links ?? [])),
                'audience_summary' => $profile?->audience_summary,
                'response_time_hours' => $profile?->response_time_hours !== null ? (int) $profile->response_time_hours : null,
                'proof_points' => array_values(array_filter((array) ($profile?->proof_points ?? []))),
                'campaign_highlights' => array_values(array_filter((array) ($profile?->campaign_highlights ?? []))),
                'portfolio_items' => $this->serializePortfolioItems((array) ($profile?->portfolio_items ?? [])),
                'promotions' => $promotions->map(fn (Product $promotion) => $this->serializePromotion($promotion))->values()->all(),
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'banner_url' => ['nullable', 'string', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'audience_summary' => ['nullable', 'string', 'max:1000'],
            'response_time_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'proof_points' => ['nullable', 'array'],
            'proof_points.*' => ['nullable', 'string', 'max:255'],
            'campaign_highlights' => ['nullable', 'array'],
            'campaign_highlights.*' => ['nullable', 'string', 'max:255'],
            'portfolio_items' => ['nullable', 'array'],
            'portfolio_items.*.title' => ['required_with:portfolio_items', 'string', 'max:120'],
            'portfolio_items.*.summary' => ['nullable', 'string', 'max:280'],
            'portfolio_items.*.outcome' => ['nullable', 'string', 'max:160'],
            'portfolio_items.*.platform' => ['nullable', Rule::in($this->promotionPlatforms())],
            'portfolio_items.*.asset_url' => ['nullable', 'url', 'max:2048'],
            'portfolio_items.*.external_url' => ['nullable', 'url', 'max:2048'],
            'social_links' => ['nullable', 'array'],
            'social_links.instagram_url' => ['nullable', 'url', 'max:255'],
            'social_links.twitter_url' => ['nullable', 'url', 'max:255'],
            'social_links.facebook_url' => ['nullable', 'url', 'max:255'],
            'social_links.youtube_url' => ['nullable', 'url', 'max:255'],
            'social_links.tiktok_url' => ['nullable', 'url', 'max:255'],
            'social_links.website_url' => ['nullable', 'url', 'max:255'],
        ]);

        $user = $request->user();
        $store = $this->resolveSellerStore($request);

        if ($store instanceof JsonResponse) {
            return $store;
        }

        /**
         * Writes to promoter_profiles, not stores.metadata. Location and the
         * promoter's website live in the profile's metadata blob because the
         * table has no column for either; everything else is a real column.
         */
        $profile = $this->promoterProfileFor($user, createIfMissing: true);

        $profile->forceFill([
            'bio' => $validated['bio'] ?? $profile->bio,
            'audience_summary' => $validated['audience_summary'] ?? null,
            'response_time_hours' => $validated['response_time_hours'] ?? null,
            'proof_points' => array_values(array_filter((array) ($validated['proof_points'] ?? []), fn ($value) => filled($value))),
            'campaign_highlights' => array_values(array_filter((array) ($validated['campaign_highlights'] ?? []), fn ($value) => filled($value))),
            'portfolio_items' => $this->sanitizePortfolioItems($validated['portfolio_items'] ?? []),
            'social_links' => array_filter((array) ($validated['social_links'] ?? [])),
            'metadata' => array_merge((array) ($profile->metadata ?? []), [
                'location' => $validated['location'] ?? null,
            ]),
        ])->save();

        $store->forceFill([
            'banner' => $validated['banner_url'] ?? $store->banner,
            'description' => $validated['bio'] ?? $store->description,
        ])->save();

        // The user's own *_url columns are not written here. They belong to
        // the person's profile and are edited there; this endpoint edits the
        // promoter storefront, whose links live on the promoter profile.

        $this->logSellerActivity($request, 'promoter_profile_updated', $store, [
            'store_id' => $store->id,
            'user_id' => $user?->id,
        ]);

        return $this->profile($request);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnership($request, $product);
        $validated = $this->validatePromotionPayload($request, $product);
        $metadata = is_array($product->metadata ?? null) ? $product->metadata : [];
        $moderation = array_merge((array) data_get($metadata, 'moderation', []), [
            'status' => 'pending',
            'resubmitted_at' => now()->toIso8601String(),
            'resubmitted_by' => $request->user()?->id,
        ]);

        $product->forceFill([
            'name' => $validated['title'],
            'slug' => $this->generateUniqueSlug($validated['title'], $product->id),
            'description' => $validated['description'],
            'short_description' => $validated['short_description'],
            'featured_image' => $validated['featured_image'] ?? $product->featured_image,
            'price_credits' => $validated['price_credits'],
            'price_ugx' => $validated['price_ugx'],
            'allow_credit_payment' => $validated['accepts_credits'],
            'allow_hybrid_payment' => $validated['accepts_hybrid'],
            'accepts_credits' => $validated['accepts_credits'],
            'status' => Product::STATUS_DRAFT,
            'is_active' => false,
            ...$this->promotionColumns($validated),
            'metadata' => $this->buildMetadata($validated, array_merge($metadata, ['moderation' => $moderation])),
        ])->save();

        $this->logSellerActivity($request, 'promotion_listing_updated', $product, [
            'promotion_id' => $product->id,
            'promotion_slug' => $product->slug,
        ]);

        return response()->json([
            'promotion' => $this->serializePromotion($product->fresh(['store.user'])),
            'message' => 'Promotion updated successfully and resubmitted for review.',
        ]);
    }

    public function pause(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnership($request, $product);

        $product->forceFill([
            'status' => Product::STATUS_PAUSED,
            'is_active' => false,
        ])->save();

        $this->logSellerActivity($request, 'promotion_listing_paused', $product, [
            'promotion_id' => $product->id,
        ]);

        return response()->json([
            'success' => true,
            'status' => Product::STATUS_PAUSED,
        ]);
    }

    public function activate(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnership($request, $product);

        $metadata = is_array($product->metadata ?? null) ? $product->metadata : [];
        $moderation = (array) data_get($metadata, 'moderation', []);
        $approvedAt = data_get($moderation, 'approved_at');
        $resubmittedAt = data_get($moderation, 'resubmitted_at');

        $needsApproval = ! $approvedAt
            || ($resubmittedAt && $resubmittedAt > $approvedAt);

        if ($needsApproval) {
            return response()->json([
                'message' => 'This promotion is pending admin approval and cannot be activated yet.',
                'status' => 'pending',
            ], 422);
        }

        $product->forceFill([
            'status' => Product::STATUS_ACTIVE,
            'is_active' => true,
            'published_at' => $product->published_at ?? now(),
        ])->save();

        $this->logSellerActivity($request, 'promotion_listing_activated', $product, [
            'promotion_id' => $product->id,
        ]);

        return response()->json([
            'success' => true,
            'status' => Product::STATUS_ACTIVE,
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->assertOwnership($request, $product);

        $this->logSellerActivity($request, 'promotion_listing_deleted', $product, [
            'promotion_id' => $product->id,
            'promotion_slug' => $product->slug,
        ]);

        $product->delete();

        return response()->json([
            'message' => 'Promotion deleted successfully.',
        ]);
    }

    public function verifyCompletionById(Request $request, int $orderId): JsonResponse
    {
        $storeId = $request->user()?->store?->id;
        $order = Order::query()
            ->where('id', $orderId)
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product.store.user', 'buyer'])
            ->firstOrFail();

        $orderItem = $order->items->first(fn (OrderItem $item) => $item->product?->product_type === 'promotion');
        abort_unless($orderItem, 404);

        return $this->verifyCompletion($request, $orderItem);
    }

    public function rejectCompletionById(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $storeId = $request->user()?->store?->id;
        $order = Order::query()
            ->where('id', $orderId)
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product.store.user', 'buyer'])
            ->firstOrFail();

        $orderItem = $order->items->first(fn (OrderItem $item) => $item->product?->product_type === 'promotion');
        abort_unless($orderItem, 404);

        if ($order->payment_status === Order::PAYMENT_REFUNDED || $order->status === Order::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'This promotion order has already been refunded.',
            ], 422);
        }

        DB::transaction(function () use ($order, $orderItem, $validated) {
            /**
             * The reversal must run before the order is stamped refunded.
             * reverseOrder() returns the buyer's money only while
             * payment_status is not yet PAYMENT_REFUNDED, so stamping first
             * marks the order refunded and silently skips the refund.
             */
            app(PromotionSettlementService::class)->reverseOrder($order, $orderItem, $validated['reason']);

            $orderItem->forceFill([
                'verification_status' => 'rejected',
                'rejection_reason' => $validated['reason'],
            ])->save();

            $order->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'payment_status' => Order::PAYMENT_REFUNDED,
                'refunded_at' => now(),
                'refund_reason' => $validated['reason'],
            ])->save();
        });

        $this->logSellerActivity($request, 'promotion_order_rejected', $orderItem, [
            'order_id' => $order->id,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Order rejected. Refund issued to buyer.',
        ]);
    }

    public function pendingVerifications(Request $request): JsonResponse
    {
        $storeId = $request->user()?->store?->id;
        $orders = Order::query()
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product.store.user', 'buyer'])
            ->latest()
            ->get()
            ->filter(function (Order $order) {
                $item = $order->items->first();

                return in_array($item?->verification_status, [null, 'pending', 'submitted'], true);
            })
            ->values();

        $perPage = $this->getPerPage($request);
        $page = max(1, (int) $request->integer('page', 1));
        $slice = $orders->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $slice->map(fn (Order $order) => $this->serializeOrder($order))->values(),
            'meta' => [
                'current_page' => $page,
                'total' => $orders->count(),
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil(max($orders->count(), 1) / $perPage)),
                'from' => $slice->isEmpty() ? null : (($page - 1) * $perPage) + 1,
                'to' => $slice->isEmpty() ? null : (($page - 1) * $perPage) + $slice->count(),
            ],
        ]);
    }

    public function showOrder(Request $request, int $orderId): JsonResponse
    {
        $storeId = $request->user()?->store?->id;
        $order = Order::query()
            ->where('id', $orderId)
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product.store.user', 'buyer'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializeOrder($order),
        ]);
    }

    public function verifyCompletion(Request $request, OrderItem $orderItem): JsonResponse
    {
        $product = $orderItem->product;
        $this->assertOwnership($request, $product);

        $order = $orderItem->order()->with(['buyer', 'items.product.store.user'])->firstOrFail();
        $settlements = app(PromotionSettlementService::class);

        if ($blocked = $settlements->releaseBlockedReason($order, $orderItem)) {
            return response()->json(['message' => $blocked], 422);
        }

        $settlement = $settlements->releaseToSeller($order, $orderItem, $request->user()?->id);

        $this->logSellerActivity($request, 'promotion_order_verified', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'settlement' => $settlement,
        ]);

        return response()->json([
            'message' => 'Completion verified successfully.',
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $storeId = $request->user()?->store?->id;
        $promotions = Product::query()
            ->promotion()
            ->where('store_id', $storeId)
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
            ])
            ->get();

        $orders = Order::query()
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product'])
            ->get();

        $settlementService = app(PromotionSettlementService::class);
        $settlements = $orders->flatMap(fn (Order $order) => $order->items->map(fn (OrderItem $item) => $settlementService->summarize($item)));

        return response()->json([
            'data' => [
                'total_promotions' => $promotions->count(),
                'active_promotions' => $promotions->where('status', Product::STATUS_ACTIVE)->count(),
                'total_orders' => $orders->count(),
                'pending_verifications' => $orders->flatMap->items->filter(fn (OrderItem $item) => in_array($item->verification_status, [null, 'pending', 'submitted'], true))->count(),
                'total_revenue_credits' => (float) $settlements->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.seller_net_credits', 0)),
                'total_revenue_ugx' => (float) $settlements->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.seller_net_ugx', 0)),
                'total_platform_fees_credits' => (float) $settlements->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.platform_fee_credits', 0)),
                'total_platform_fees_ugx' => (float) $settlements->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.platform_fee_ugx', 0)),
                'net_revenue_credits' => (float) $settlements->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.seller_net_credits', 0)),
                'net_revenue_ugx' => (float) $settlements->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.seller_net_ugx', 0)),
                'settled_orders' => $settlements->filter(fn (array $summary) => data_get($summary, 'status') === 'settled')->count(),
                'average_rating' => $promotions->count() > 0 ? round((float) $promotions->avg('average_rating'), 2) : 0,
                'conversion_rate' => $promotions->sum('total_orders') > 0 ? round($promotions->sum('completed_orders') / max($promotions->sum('total_orders'), 1), 4) : 0,
                'top_performing_promotion' => $promotions->sortByDesc('completed_orders')->first() ? $this->serializePromotion($promotions->sortByDesc('completed_orders')->first()) : null,
            ],
        ]);
    }

    protected function getPerPage(Request $request, int $default = 20, int $max = 100): int
    {
        return parent::getPerPage($request, $default, $max);
    }

    private function resolveSellerStore(Request $request): Store|JsonResponse
    {
        $user = $request->user();
        $store = $user?->store;

        if ($store) {
            return $store;
        }

        if (! $user) {
            return response()->json([
                'message' => 'You must be signed in to create a promotion service.',
            ], 401);
        }

        try {
            return app(StoreService::class)->create($user, [
                'name' => $this->defaultStoreName($user),
                'description' => 'Auto-created seller storefront for promotion services.',
                'owner_mode' => $user->artist ? 'artist' : 'user',
                'metadata' => [
                    'created_from' => 'promotion_listing',
                    'auto_created' => true,
                    'auto_created_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function defaultStoreName(User $user): string
    {
        $baseName = trim((string) ($user->artist?->stage_name ?? $user->name ?? $user->username ?? 'Seller'));

        return Str::limit($baseName.' Promotions', 255, '');
    }

    private function validatePromotionPayload(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'string', 'max:100'],
            'platform' => ['required', 'string', 'max:100'],
            'price_credits' => ['required', 'integer', 'min:0'],
            'price_ugx' => ['required', 'numeric', 'min:0'],
            'accepts_credits' => ['required', 'boolean'],
            'accepts_ugx' => ['required', 'boolean'],
            'accepts_hybrid' => ['required', 'boolean'],
            'estimated_reach' => ['required', 'integer', 'min:0'],
            'audience_niches' => ['nullable', 'array'],
            'audience_niches.*' => ['nullable', 'string', 'max:100'],
            'audience_regions' => ['nullable', 'array'],
            'audience_regions.*' => ['nullable', 'string', 'max:100'],
            'content_formats' => ['nullable', 'array'],
            'content_formats.*' => ['nullable', 'string', 'max:100'],
            'delivery_days_min' => ['required', 'integer', 'min:1', 'max:365'],
            'delivery_days_max' => ['required', 'integer', 'min:1', 'max:365', 'gte:delivery_days_min'],
            'requirements' => ['nullable', 'array'],
            'requirements.action' => ['nullable', 'string', 'max:500'],
            'requirements.duration_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'requirements.hashtags' => ['nullable', 'array'],
            'requirements.hashtags.*' => ['nullable', 'string', 'max:100'],
            'platform_specifics' => ['nullable', 'array'],
            'platform_specifics.channel' => ['nullable', 'string', 'max:255'],
            'platform_specifics.placement' => ['nullable', 'string', 'max:255'],
            'platform_specifics.proof' => ['nullable', 'string', 'max:500'],
            'platform_specifics.timing' => ['nullable', 'string', 'max:255'],
            'deliverables' => ['nullable', 'array'],
            'deliverables.*' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'featured_image' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    /**
     * The list-valued and free-text parts of a promotion listing.
     *
     * The scalars browse filters on — type, platform, reach, delivery window
     * — are columns now, so they are not duplicated here. `accepts_ugx` is
     * gone too: it is price_ugx > 0, which is how every serializer already
     * derived it.
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(array $validated, array $existing = []): array
    {
        return array_merge($existing, [
            'audience_niches' => array_values(array_filter((array) ($validated['audience_niches'] ?? []), fn ($value) => filled($value))),
            'audience_regions' => array_values(array_filter((array) ($validated['audience_regions'] ?? []), fn ($value) => filled($value))),
            'content_formats' => array_values(array_filter((array) ($validated['content_formats'] ?? []), fn ($value) => filled($value))),
            'requirements' => $validated['requirements'] ?? null,
            'platform_specifics' => array_filter((array) ($validated['platform_specifics'] ?? []), fn ($value) => filled($value)),
            'deliverables' => array_values(array_filter($validated['deliverables'] ?? [], fn ($value) => filled($value))),
            'terms' => $validated['terms'] ?? null,
        ]);
    }

    /**
     * The promotion columns a create or update writes.
     *
     * @return array<string, mixed>
     */
    private function promotionColumns(array $validated): array
    {
        return [
            'promotion_type' => $validated['type'],
            'promotion_platform' => $validated['platform'],
            'estimated_reach' => $validated['estimated_reach'],
            'delivery_days_min' => $validated['delivery_days_min'],
            'delivery_days_max' => $validated['delivery_days_max'],
        ];
    }

    /**
     * The one promoter profile for this user.
     *
     * Reuses the onboarding service so a seller who reaches this editor
     * without having gone through /become-promoter gets the same record,
     * slug and promoter capability grant as anyone else — rather than a
     * second, differently-shaped profile in another table.
     */
    private function promoterProfileFor(?User $user, bool $createIfMissing = false): ?PromoterProfile
    {
        if (! $user) {
            return null;
        }

        $profile = PromoterProfile::where('user_id', $user->id)->first();

        if ($profile || ! $createIfMissing) {
            return $profile;
        }

        return app(PromoterOnboardingService::class)->onboard($user, []);
    }

    /**
     * Platforms a portfolio item may be attributed to.
     *
     * This was called by the profile validation and never defined, so every
     * profile update carrying portfolio items died with "Call to undefined
     * method". The list mirrors the platform values promotion listings use.
     *
     * @return list<string>
     */
    private function promotionPlatforms(): array
    {
        return [
            'instagram', 'tiktok', 'twitter', 'facebook', 'youtube',
            'whatsapp', 'telegram', 'radio', 'tv', 'blog', 'podcast', 'other',
        ];
    }

    private function assertOwnership(Request $request, ?Product $product): void
    {
        abort_if(
            ! $product
            || $product->product_type !== Product::TYPE_PROMOTION
            || $product->store?->user_id !== $request->user()?->id,
            404
        );
    }

    private function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title);
        $normalizedBaseSlug = $baseSlug !== '' ? $baseSlug : 'promotion-service';
        $slug = $normalizedBaseSlug;
        $suffix = 1;

        while (
            Product::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$normalizedBaseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function serializePromotion(Product $promotion): array
    {
        $promotion->loadMissing(['store.user']);
        $metadata = is_array($promotion->metadata ?? null) ? $promotion->metadata : [];

        return [
            'id' => $promotion->id,
            'slug' => $promotion->slug,
            'title' => $promotion->name,
            'short_description' => $promotion->short_description ?: Str::limit((string) $promotion->description, 120),
            'description' => $promotion->description,
            'type' => (string) ($promotion->promotion_type ?? 'social_media_mention'),
            'platform' => (string) ($promotion->promotion_platform ?? 'other'),
            'price_credits' => (int) ($promotion->price_credits ?? 0),
            'price_ugx' => (float) ($promotion->price_ugx ?? 0),
            'accepts_credits' => (bool) ($promotion->allow_credit_payment || $promotion->accepts_credits),
            // Derived, like every other serializer already did — it was only
            // ever stored because this one read it from metadata instead.
            'accepts_ugx' => (float) ($promotion->price_ugx ?? 0) > 0,
            'accepts_hybrid' => (bool) ($promotion->allow_hybrid_payment),
            'estimated_reach' => (int) ($promotion->estimated_reach ?? 0),
            'audience_niches' => array_values(array_filter((array) data_get($metadata, 'audience_niches', []))),
            'audience_regions' => array_values(array_filter((array) data_get($metadata, 'audience_regions', []))),
            'content_formats' => array_values(array_filter((array) data_get($metadata, 'content_formats', []))),
            'delivery_days_min' => (int) ($promotion->delivery_days_min ?? 1),
            'delivery_days_max' => (int) ($promotion->delivery_days_max ?? 7),
            'requirements' => data_get($metadata, 'requirements'),
            'platform_specifics' => data_get($metadata, 'platform_specifics', []),
            'deliverables' => array_values(array_filter((array) data_get($metadata, 'deliverables', []), fn ($value) => filled($value))),
            'terms' => data_get($metadata, 'terms'),
            'rating_average' => (float) ($promotion->average_rating ?? 0),
            'rating_count' => (int) ($promotion->rating_count ?? $promotion->review_count ?? 0),
            'total_orders' => (int) ($promotion->total_orders ?? 0),
            'completed_orders' => (int) ($promotion->completed_orders ?? 0),
            'is_featured' => (bool) ($promotion->is_featured ?? false),
            'is_top_rated' => (float) ($promotion->average_rating ?? 0) >= 4.5,
            'promoter' => $promotion->store?->user ? $this->serializeUserSummary($promotion->store->user) : null,
            'featured_image_url' => $promotion->featured_image_url,
            'status' => Product::promotionStatusForWire($promotion->status),
            'created_at' => optional($promotion->created_at)->toIso8601String(),
        ];
    }

    private function serializeOrder(Order $order): array
    {
        $item = $order->items->first();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => match ($item?->verification_status) {
                'submitted' => 'verification_submitted',
                'verified' => 'completed',
                'rejected' => 'disputed',
                default => 'pending_verification',
            },
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'credit_amount' => (int) ($order->credit_amount ?? 0),
            'ugx_amount' => (float) ($order->total_ugx ?? 0),
            'total_credits' => (int) ($order->total_credits ?? 0),
            'total_ugx' => (float) ($order->total_ugx ?? 0),
            'promotion' => $item?->product ? $this->serializePromotion($item->product) : null,
            'song' => null,
            'buyer' => $order->buyer ? $this->serializeUserSummary($order->buyer) : null,
            'notes' => $order->customer_notes ?? null,
            'verification' => [
                'status' => $item?->verification_status ?? 'pending',
                'submitted_at' => optional($item?->verification_submitted_at)->toIso8601String(),
                'verified_at' => optional($item?->verified_at)->toIso8601String(),
                'verification_url' => $item?->verification_url ?? null,
                'verification_notes' => $item?->verification_notes ?? null,
                'verification_files' => is_array($item?->verification_proof ?? null) ? $item->verification_proof : [],
                'rejection_reason' => $item?->rejection_reason ?? null,
            ],
            'dispute' => [
                'is_disputed' => ! empty($item?->dispute_reason),
                'dispute_reason' => $item?->dispute_reason ?? null,
                'reason' => $item?->dispute_reason ?? null,
                'disputed_at' => optional($item?->updated_at)->toIso8601String(),
                'created_at' => optional($order->created_at)->toIso8601String(),
                'resolved_at' => optional($order->refunded_at)->toIso8601String(),
                'resolution' => null,
                'resolution_notes' => $order->refund_reason ?? null,
            ],
            'created_at' => optional($order->created_at)->toIso8601String(),
            'expected_delivery_at' => optional($order->created_at?->copy()->addDays(3))->toIso8601String(),
            'completed_at' => optional($order->completed_at)->toIso8601String(),
        ];
    }

    private function serializeUserSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar_url' => $user->avatar_url ?? $user->avatar ?? null,
            'is_verified' => (bool) ($user->is_verified ?? false),
            'follower_count' => (int) ($user->followers_count ?? 0),
        ];
    }

    /**
     * The promoter's channels.
     *
     * The profile's own links win; the user's personal columns are the
     * fallback for a promoter who has not set storefront links yet. A
     * promoter often promotes on a channel that is not their personal
     * profile, which is why the two are allowed to differ at all — but the
     * editor now writes only the profile, so there is one place to change.
     *
     * @param  array<string, mixed>  $profileLinks
     * @return array<string, string|null>
     */
    private function serializePromoterSocialLinks(?User $user, array $profileLinks = []): array
    {
        return [
            'instagram_url' => $profileLinks['instagram_url'] ?? $user?->instagram_url ?? null,
            'twitter_url' => $profileLinks['twitter_url'] ?? $user?->twitter_url ?? null,
            'facebook_url' => $profileLinks['facebook_url'] ?? $user?->facebook_url ?? null,
            'youtube_url' => $profileLinks['youtube_url'] ?? $user?->youtube_url ?? null,
            'tiktok_url' => $profileLinks['tiktok_url'] ?? $user?->tiktok_url ?? null,
            'website_url' => $profileLinks['website_url'] ?? null,
        ];
    }

    private function formatLocation(?string $city, ?string $country, ?string $fallback = null): ?string
    {
        $parts = array_values(array_filter([$city, $country]));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $fallback;
    }

    private function sanitizePortfolioItems(array $items): array
    {
        return collect($items)
            ->map(function ($item) {
                $item = is_array($item) ? $item : [];
                $title = trim((string) ($item['title'] ?? ''));

                if ($title === '') {
                    return null;
                }

                return array_filter([
                    'title' => $title,
                    'summary' => filled($item['summary'] ?? null) ? trim((string) $item['summary']) : null,
                    'outcome' => filled($item['outcome'] ?? null) ? trim((string) $item['outcome']) : null,
                    'platform' => filled($item['platform'] ?? null) ? (string) $item['platform'] : null,
                    'asset_url' => filled($item['asset_url'] ?? null) ? trim((string) $item['asset_url']) : null,
                    'external_url' => filled($item['external_url'] ?? null) ? trim((string) $item['external_url']) : null,
                ], fn ($value) => filled($value));
            })
            ->filter()
            ->values()
            ->all();
    }

    private function serializePortfolioItems(array $items): array
    {
        return collect($items)
            ->map(function ($item) {
                $item = is_array($item) ? $item : [];
                $title = trim((string) ($item['title'] ?? ''));

                if ($title === '') {
                    return null;
                }

                return [
                    'title' => $title,
                    'summary' => filled($item['summary'] ?? null) ? trim((string) $item['summary']) : null,
                    'outcome' => filled($item['outcome'] ?? null) ? trim((string) $item['outcome']) : null,
                    'platform' => filled($item['platform'] ?? null) ? (string) $item['platform'] : null,
                    'asset_url' => filled($item['asset_url'] ?? null) ? trim((string) $item['asset_url']) : null,
                    'external_url' => filled($item['external_url'] ?? null) ? trim((string) $item['external_url']) : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function logSellerActivity(Request $request, string $action, $auditable, array $data = []): void
    {
        if (! $request->user() || ! $auditable || ! isset($auditable->id)) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => $action,
                'auditable_type' => get_class($auditable),
                'auditable_id' => $auditable->id,
                'old_values' => null,
                'new_values' => $data,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
            ]);
        } catch (Throwable) {
            // Seller actions should continue even if audit logging fails.
        }
    }
}
