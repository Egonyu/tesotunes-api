<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\StoreReview;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PromotionMarketplaceController extends Controller
{
    private const DEFAULT_PROMOTION_TYPES = [
        'social_media_mention',
        'live_stream_promotion',
        'radio_mention',
        'dj_shoutout',
        'ticket_giveaway',
        'content_creation',
        'playlist_inclusion',
        'collaboration_offer',
    ];

    private const DEFAULT_PLATFORMS = [
        'instagram',
        'tiktok',
        'facebook',
        'youtube',
        'twitter',
        'spotify',
        'apple_music',
        'radio',
        'club',
        'event',
        'blog',
        'podcast',
        'other',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'short_description' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'type' => 'required|string',
            'platform' => 'required|string',
            'price_credits' => 'required|integer|min:0',
            'price_ugx' => 'required|numeric|min:0',
            'accepts_credits' => 'required|boolean',
            'accepts_ugx' => 'required|boolean',
            'accepts_hybrid' => 'required|boolean',
            'estimated_reach' => 'nullable|integer|min:0',
            'delivery_days_min' => 'required|integer|min:1|max:365',
            'delivery_days_max' => 'required|integer|min:1|max:365',
            'requirements' => 'nullable|array',
            'requirements.action' => 'nullable|string|max:255',
            'requirements.duration_hours' => 'nullable|integer|min:1',
            'requirements.hashtags' => 'nullable|array',
            'requirements.hashtags.*' => 'nullable|string|max:100',
            'deliverables' => 'nullable|array',
            'deliverables.*' => 'nullable|string|max:255',
            'terms' => 'nullable|string|max:5000',
            'featured_image' => 'nullable|string|max:2048',
        ]);

        $user = $request->user();
        $store = $user?->store;

        if (! $user || ! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Create or connect a store before publishing a promotion service.',
            ], 422);
        }

        $promotion = Product::create([
            'store_id' => $store->id,
            'name' => $validated['title'],
            'slug' => Str::slug($validated['title']).'-'.Str::random(6),
            'description' => $validated['description'],
            'short_description' => $validated['short_description'],
            'product_type' => Product::TYPE_PROMOTION,
            'price_credits' => $validated['price_credits'],
            'price_ugx' => $validated['price_ugx'],
            'allow_credit_payment' => (bool) $validated['accepts_credits'],
            'allow_hybrid_payment' => (bool) $validated['accepts_hybrid'],
            'accepts_credits' => (bool) $validated['accepts_credits'],
            'featured_image' => $validated['featured_image'] ?? null,
            'status' => Product::STATUS_DRAFT,
            'is_featured' => false,
            'metadata' => array_filter([
                'promotion_type' => $validated['type'],
                'platform' => $validated['platform'],
                'estimated_reach' => $validated['estimated_reach'] ?? 0,
                'delivery_days_min' => $validated['delivery_days_min'],
                'delivery_days_max' => $validated['delivery_days_max'],
                'requirements' => $validated['requirements'] ?? null,
                'deliverables' => $validated['deliverables'] ?? [],
                'terms' => $validated['terms'] ?? null,
                'moderation_status' => 'pending',
                'created_by_user_id' => $user->id,
            ], fn ($value) => $value !== null),
        ]);

        return response()->json([
            'success' => true,
            'promotion' => $this->serializePromotionListItem($promotion->fresh(['store.user'])),
            'data' => $this->serializePromotionListItem($promotion->fresh(['store.user'])),
            'message' => 'Promotion created successfully.',
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $query = Product::query()
            ->promotion()
            ->active()
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedReviews as rating_count',
            ])
            ->when($request->filled('featured'), fn ($builder) => $builder->where('is_featured', filter_var($request->boolean('featured'), FILTER_VALIDATE_BOOL)))
            ->when($request->filled('type'), fn ($builder) => $builder->where(function ($inner) use ($request) {
                $type = $request->string('type')->toString();

                $inner->where('metadata->promotion_type', $type)
                    ->orWhere('metadata->type', $type)
                    ->orWhere('metadata->category', $type);
            }))
            ->when($request->filled('platform'), fn ($builder) => $builder->where(function ($inner) use ($request) {
                $platform = $request->string('platform')->toString();

                $inner->where('metadata->platform', $platform)
                    ->orWhere('metadata->platform_slug', $platform);
            }))
            ->when($request->filled('min_reach'), fn ($builder) => $builder->whereRaw('COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.estimated_reach")), 0) >= ?', [(int) $request->integer('min_reach')]))
            ->when($request->filled('max_reach'), fn ($builder) => $builder->whereRaw('COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.estimated_reach")), 0) <= ?', [(int) $request->integer('max_reach')]))
            ->when($request->filled('min_price_credits'), fn ($builder) => $builder->where('price_credits', '>=', (int) $request->integer('min_price_credits')))
            ->when($request->filled('max_price_credits'), fn ($builder) => $builder->where('price_credits', '<=', (int) $request->integer('max_price_credits')))
            ->when($request->filled('min_price_ugx'), fn ($builder) => $builder->where('price_ugx', '>=', (float) $request->input('min_price_ugx')))
            ->when($request->filled('max_price_ugx'), fn ($builder) => $builder->where('price_ugx', '<=', (float) $request->input('max_price_ugx')))
            ->when($request->filled('rating_min'), fn ($builder) => $builder->where('average_rating', '>=', (float) $request->input('rating_min')))
            ->when($request->filled('delivery_days_max'), fn ($builder) => $builder->whereRaw('COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.delivery_days_max")), 0) <= ?', [(int) $request->integer('delivery_days_max')]))
            ->when($request->boolean('verified'), fn ($builder) => $builder->whereHas('store.user', function ($userQuery) {
                $userQuery->where('is_verified', true)
                    ->orWhereNotNull('email_verified_at');
            }))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = trim((string) $request->input('search', ''));

                $builder->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('store', fn ($storeQuery) => $storeQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('store.user', fn ($userQuery) => $userQuery->where('username', 'like', "%{$search}%")
                            ->orWhere('display_name', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%"));
                });
            });

        $sort = $request->string('sort')->toString();
        match ($sort) {
            'price_asc' => $query->orderBy('price_ugx'),
            'price_desc' => $query->orderByDesc('price_ugx'),
            'rating' => $query->orderByDesc('average_rating')->orderByDesc('rating_count'),
            'popularity' => $query->orderByDesc('completed_orders')->orderByDesc('rating_count'),
            default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
        };

        $promotions = $query->paginate($perPage);

        return response()->json([
            'data' => collect($promotions->items())
                ->map(fn (Product $promotion) => $this->serializePromotionListItem($promotion))
                ->values(),
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

    public function show(string $slug): JsonResponse
    {
        $promotion = Product::query()
            ->promotion()
            ->active()
            ->with([
                'store.user',
                'approvedReviews.user',
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializePromotionDetail($promotion),
        ]);
    }

    public function reviews(Request $request, string $slug): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 10), 100));

        $promotion = Product::query()
            ->promotion()
            ->active()
            ->where('slug', $slug)
            ->firstOrFail();

        $reviews = $promotion->approvedReviews()
            ->with('user')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => collect($reviews->items())
                ->map(fn (StoreReview $review) => $this->serializeReview($promotion, $review))
                ->values(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'total' => $reviews->total(),
                'per_page' => $reviews->perPage(),
                'last_page' => $reviews->lastPage(),
            ],
        ]);
    }

    public function platforms(): JsonResponse
    {
        return response()->json([
            'data' => collect(self::DEFAULT_PLATFORMS)
                ->map(fn (string $platform) => [
                    'slug' => $platform,
                    'name' => Str::of($platform)->headline()->toString(),
                    'icon_url' => null,
                ])
                ->values(),
        ]);
    }

    public function promoter(string $username): JsonResponse
    {
        $user = User::query()
            ->with(['store'])
            ->where('username', $username)
            ->firstOrFail();

        $store = $user->store;
        $promotionQuery = Product::query()
            ->promotion()
            ->active()
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedReviews as rating_count',
            ])
            ->when($store, fn ($builder) => $builder->where('store_id', $store->id));

        $promotions = $promotionQuery
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->get();

        $averageRating = $promotions->count() > 0
            ? round((float) $promotions->avg('average_rating'), 2)
            : 0.0;

        $completedOrders = (int) $promotions->sum('completed_orders');
        $activePromotions = (int) $promotions->where('status', Product::STATUS_ACTIVE)->count();
        $featuredPromotions = (int) $promotions->where('is_featured', true)->count();
        $platforms = $promotions
            ->map(fn (Product $promotion) => $this->promotionPlatform($promotion))
            ->filter(fn (string $platform) => $platform !== '' && $platform !== 'other')
            ->unique()
            ->values();
        $serviceTypes = $promotions
            ->map(fn (Product $promotion) => $this->promotionType($promotion))
            ->filter(fn (string $type) => $type !== '')
            ->unique()
            ->values();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->display_name ?: $user->name,
                'username' => $user->username,
                'avatar_url' => $this->resolveAvatarUrl($user->avatar),
                'banner_url' => $this->resolveMediaUrl($user->banner),
                'bio' => $user->bio ?: null,
                'location' => collect([$user->city, $user->country])->filter()->implode(', ') ?: null,
                'is_verified' => (bool) ($user->is_verified || $user->email_verified_at),
                'follower_count' => (int) ($user->followers()->count()),
                'total_promotions' => $promotions->count(),
                'active_promotions' => $activePromotions,
                'featured_promotions' => $featuredPromotions,
                'average_rating' => $averageRating,
                'completed_orders' => $completedOrders,
                'platforms' => $platforms,
                'service_types' => $serviceTypes,
                'social_links' => $this->promoterSocialLinks($user),
                'promotions' => $promotions->map(fn (Product $promotion) => $this->serializePromotionListItem($promotion))->values(),
            ],
        ]);
    }

    public function myPromotions(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = strtolower(trim((string) $request->input('status', '')));

        $query = Product::query()
            ->promotion()
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedReviews as rating_count',
            ])
            ->whereHas('store', fn ($storeQuery) => $storeQuery->where('user_id', $user?->id));

        if ($status !== '' && $status !== 'all') {
            $query->where(function ($builder) use ($status) {
                match ($status) {
                    'pending' => $builder->where('status', Product::STATUS_DRAFT),
                    'active' => $builder->where('status', Product::STATUS_ACTIVE),
                    'paused' => $builder->where('status', Product::STATUS_ARCHIVED)->where(function ($inner) {
                        $inner->whereNull('metadata')
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.moderation_status')) <> 'rejected'");
                    }),
                    'rejected' => $builder->where('status', Product::STATUS_ARCHIVED)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.moderation_status')) = 'rejected'"),
                    default => $builder->where('status', $status),
                };
            });
        }

        $promotions = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($promotions->items())
                ->map(fn (Product $promotion) => $this->serializePromotionListItem($promotion))
                ->values(),
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

    public function update(Request $request, Product $promotion): JsonResponse
    {
        $this->assertOwnsPromotion($request, $promotion);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'short_description' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:5000',
            'type' => 'sometimes|string',
            'platform' => 'sometimes|string',
            'price_credits' => 'sometimes|integer|min:0',
            'price_ugx' => 'sometimes|numeric|min:0',
            'accepts_credits' => 'sometimes|boolean',
            'accepts_ugx' => 'sometimes|boolean',
            'accepts_hybrid' => 'sometimes|boolean',
            'estimated_reach' => 'nullable|integer|min:0',
            'delivery_days_min' => 'sometimes|integer|min:1|max:365',
            'delivery_days_max' => 'sometimes|integer|min:1|max:365',
            'requirements' => 'nullable|array',
            'requirements.action' => 'nullable|string|max:255',
            'requirements.duration_hours' => 'nullable|integer|min:1',
            'requirements.hashtags' => 'nullable|array',
            'requirements.hashtags.*' => 'nullable|string|max:100',
            'deliverables' => 'nullable|array',
            'deliverables.*' => 'nullable|string|max:255',
            'terms' => 'nullable|string|max:5000',
            'featured_image' => 'nullable|string|max:2048',
        ]);

        $metadata = $this->promotionMetadata($promotion);

        foreach (['type' => 'promotion_type', 'platform' => 'platform', 'estimated_reach' => 'estimated_reach', 'delivery_days_min' => 'delivery_days_min', 'delivery_days_max' => 'delivery_days_max', 'requirements' => 'requirements', 'deliverables' => 'deliverables', 'terms' => 'terms'] as $inputKey => $metadataKey) {
            if (array_key_exists($inputKey, $validated)) {
                $metadata[$metadataKey] = $validated[$inputKey];
            }
        }

        $promotion->update(array_filter([
            'name' => $validated['title'] ?? null,
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'price_credits' => $validated['price_credits'] ?? null,
            'price_ugx' => $validated['price_ugx'] ?? null,
            'allow_credit_payment' => $validated['accepts_credits'] ?? null,
            'allow_hybrid_payment' => $validated['accepts_hybrid'] ?? null,
            'accepts_credits' => $validated['accepts_credits'] ?? null,
            'featured_image' => $validated['featured_image'] ?? null,
            'metadata' => $metadata,
        ], fn ($value) => $value !== null));

        return response()->json([
            'success' => true,
            'promotion' => $this->serializePromotionListItem($promotion->fresh(['store.user'])),
            'data' => $this->serializePromotionListItem($promotion->fresh(['store.user'])),
        ]);
    }

    public function destroy(Request $request, Product $promotion): JsonResponse
    {
        $this->assertOwnsPromotion($request, $promotion);
        $promotion->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    public function pause(Request $request, Product $promotion): JsonResponse
    {
        $this->assertOwnsPromotion($request, $promotion);
        $promotion->update(['status' => Product::STATUS_ARCHIVED]);

        return response()->json([
            'success' => true,
            'status' => 'paused',
        ]);
    }

    public function activate(Request $request, Product $promotion): JsonResponse
    {
        $this->assertOwnsPromotion($request, $promotion);
        $promotion->update(['status' => Product::STATUS_ACTIVE]);

        return response()->json([
            'success' => true,
            'status' => 'active',
        ]);
    }

    public function myPromotionOrders(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = strtolower(trim((string) $request->input('status', '')));

        $query = OrderItem::query()
            ->with(['product.store.user', 'order.buyer'])
            ->whereHas('product', fn ($builder) => $builder->promotion())
            ->whereHas('product.store', fn ($storeQuery) => $storeQuery->where('user_id', $request->user()?->id));

        if ($status !== '' && $status !== 'all') {
            $query->where(function ($builder) use ($status) {
                match ($status) {
                    'pending_verification' => $builder->where(function ($inner) {
                        $inner->whereNull('verification_status')
                            ->orWhere('verification_status', 'pending');
                    })->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', '!=', Order::STATUS_COMPLETED)),
                    'verification_submitted' => $builder->where('verification_status', 'submitted'),
                    'completed' => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                    'disputed' => $builder->where(function ($inner) {
                        $inner->whereNotNull('dispute_reason')->orWhere('verification_status', 'disputed');
                    }),
                    default => $builder,
                };
            });
        }

        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'data' => collect($orders->items())
                ->map(fn (OrderItem $item) => $this->serializePromotionOrder($item))
                ->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'last_page' => $orders->lastPage(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
        ]);
    }

    public function myPromotionOrder(Request $request, int $orderId): JsonResponse
    {
        $item = $this->sellerOrderItemQuery($request->user())->findOrFail($orderId);

        return response()->json([
            'data' => $this->serializePromotionOrder($item),
        ]);
    }

    public function verifyOrder(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'verified' => 'required|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $item = $this->sellerOrderItemQuery($request->user())->findOrFail($orderId);
        $order = $item->order;

        if ($validated['verified']) {
            $item->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verification_notes' => $validated['notes'] ?? $item->verification_notes,
                'rejection_reason' => null,
            ]);

            $order?->update([
                'status' => Order::STATUS_COMPLETED,
                'completed_at' => now(),
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => $order->paid_at ?? now(),
            ]);

            app(PromotionSettlementService::class)->settleOrder($order, $item);
        }

        return response()->json([
            'success' => true,
            'payment_released' => (bool) $validated['verified'],
        ]);
    }

    public function rejectOrder(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $item = $this->sellerOrderItemQuery($request->user())->findOrFail($orderId);
        $order = $item->order;

        $item->update([
            'verification_status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'verified_at' => now(),
        ]);

        $order?->update([
            'status' => Order::STATUS_REFUNDED,
            'payment_status' => Order::PAYMENT_REFUNDED,
            'refund_amount' => (float) ($order->total_ugx ?? $order->total_amount ?? 0),
            'refunded_at' => now(),
        ]);

        app(PromotionSettlementService::class)->reverseOrder($order, $item, $validated['reason']);

        return response()->json([
            'success' => true,
            'refund_issued' => true,
        ]);
    }

    public function sellerAnalytics(Request $request): JsonResponse
    {
        $promotions = Product::query()
            ->promotion()
            ->whereHas('store', fn ($storeQuery) => $storeQuery->where('user_id', $request->user()?->id))
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedReviews as rating_count',
            ])
            ->get();

        $orders = $this->sellerOrderItemQuery($request->user())->get();

        $settlementService = app(PromotionSettlementService::class);
        $settlementSummaries = $orders->map(fn (OrderItem $item) => $settlementService->summarize($item));
        $totalRevenueUgx = (float) $settlementSummaries->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.seller_net_ugx', 0));
        $totalRevenueCredits = (float) $settlementSummaries->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.seller_net_credits', 0));
        $totalPlatformFeesUgx = (float) $settlementSummaries->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.platform_fee_ugx', 0));
        $totalPlatformFeesCredits = (float) $settlementSummaries->sum(fn (array $summary) => (float) data_get($summary, 'breakdown.platform_fee_credits', 0));
        $settledOrders = $settlementSummaries->filter(fn (array $summary) => data_get($summary, 'status') === 'settled')->count();
        $pendingVerifications = $orders->filter(fn (OrderItem $item) => in_array($item->verification_status, [null, 'pending', 'submitted'], true) && ! $item->dispute_reason)->count();

        $topPromotion = $promotions->sortByDesc(fn (Product $promotion) => (int) ($promotion->completed_orders ?? 0))->first();

        return response()->json([
            'data' => [
                'total_promotions' => $promotions->count(),
                'active_promotions' => $promotions->where('status', Product::STATUS_ACTIVE)->count(),
                'total_orders' => $orders->count(),
                'completed_orders' => $orders->filter(fn (OrderItem $item) => $item->order?->status === Order::STATUS_COMPLETED)->count(),
                'pending_verifications' => $pendingVerifications,
                'total_revenue_credits' => $totalRevenueCredits,
                'total_revenue_ugx' => $totalRevenueUgx,
                'total_platform_fees_credits' => $totalPlatformFeesCredits,
                'total_platform_fees_ugx' => $totalPlatformFeesUgx,
                'net_revenue_credits' => $totalRevenueCredits,
                'net_revenue_ugx' => $totalRevenueUgx,
                'settled_orders' => $settledOrders,
                'average_rating' => $promotions->count() > 0 ? round((float) $promotions->avg('average_rating'), 2) : 0,
                'conversion_rate' => $promotions->sum('total_orders') > 0
                    ? round($promotions->sum('completed_orders') / max($promotions->sum('total_orders'), 1), 4)
                    : 0,
                'top_performing_promotion' => $topPromotion ? $this->serializePromotionListItem($topPromotion) : null,
            ],
        ]);
    }

    private function serializePromotionListItem(Product $promotion): array
    {
        $promotion->loadMissing(['store.user']);
        $metadata = $this->promotionMetadata($promotion);
        $promoter = $promotion->store?->user;

        return [
            'id' => $promotion->id,
            'slug' => $promotion->slug,
            'title' => $promotion->name,
            'short_description' => $promotion->short_description ?: Str::limit((string) $promotion->description, 120),
            'type' => $this->promotionType($promotion),
            'platform' => $this->promotionPlatform($promotion),
            'price_credits' => (int) ($promotion->price_credits ?? 0),
            'price_ugx' => (float) ($promotion->price_ugx ?? 0),
            'accepts_credits' => (bool) ($promotion->allow_credit_payment || $promotion->accepts_credits),
            'accepts_ugx' => (float) ($promotion->price_ugx ?? 0) > 0,
            'accepts_hybrid' => (bool) ($promotion->allow_hybrid_payment),
            'estimated_reach' => (int) ($metadata['estimated_reach'] ?? $metadata['reach'] ?? 0),
            'delivery_days_min' => (int) ($metadata['delivery_days_min'] ?? 1),
            'delivery_days_max' => (int) ($metadata['delivery_days_max'] ?? 7),
            'rating_average' => (float) ($promotion->average_rating ?? 0),
            'rating_count' => (int) ($promotion->rating_count ?? $promotion->review_count ?? 0),
            'total_orders' => (int) ($promotion->total_orders ?? 0),
            'completed_orders' => (int) ($promotion->completed_orders ?? 0),
            'is_featured' => (bool) $promotion->is_featured,
            'is_top_rated' => (float) ($promotion->average_rating ?? 0) >= 4.5 && (int) ($promotion->review_count ?? 0) >= 3,
            'promoter' => $this->serializePromoterSummary($promoter),
            'featured_image_url' => $promotion->featured_image_url,
            'status' => $this->mapFrontendStatus($promotion),
            'created_at' => optional($promotion->created_at)->toIso8601String(),
        ];
    }

    private function serializePromotionDetail(Product $promotion): array
    {
        $listItem = $this->serializePromotionListItem($promotion);
        $metadata = $this->promotionMetadata($promotion);

        return array_merge($listItem, [
            'description' => (string) $promotion->description,
            'requirements' => $metadata['requirements'] ?? null,
            'deliverables' => array_values(array_filter((array) ($metadata['deliverables'] ?? []))),
            'terms' => $metadata['terms'] ?? $promotion->short_description,
            'reviews' => collect($promotion->approvedReviews ?? [])
                ->map(fn (StoreReview $review) => $this->serializeReview($promotion, $review))
                ->values()
                ->all(),
        ]);
    }

    private function serializeReview(Product $promotion, StoreReview $review): array
    {
        $reviewer = $review->user;

        return [
            'id' => $review->id,
            'promotion_id' => $promotion->id,
            'order_id' => $review->order_id,
            'rating' => (int) $review->rating,
            'comment' => (string) ($review->review ?? $review->comment ?? ''),
            'would_recommend' => (int) $review->rating >= 4,
            'helpful_count' => (int) ($review->helpful_count ?? 0),
            'reviewer' => [
                'id' => $reviewer?->id ?? 0,
                'name' => $reviewer?->display_name ?? $reviewer?->name ?? 'Anonymous',
                'username' => $reviewer?->username ?? 'anonymous',
                'avatar_url' => $this->resolveAvatarUrl($reviewer?->avatar),
                'is_verified' => (bool) ($reviewer?->is_verified || $reviewer?->email_verified_at),
                'follower_count' => (int) ($reviewer?->followers()?->count() ?? 0),
            ],
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];
    }

    private function serializePromoterSummary(?User $user): array
    {
        return [
            'id' => $user?->id ?? 0,
            'name' => $user?->display_name ?: $user?->name ?: 'Unknown Promoter',
            'username' => $user?->username ?? 'unknown',
            'avatar_url' => $this->resolveAvatarUrl($user?->avatar),
            'is_verified' => (bool) ($user?->is_verified || $user?->email_verified_at),
            'follower_count' => (int) ($user?->followers()->count() ?? 0),
        ];
    }

    private function promotionType(Product $promotion): string
    {
        $metadata = $this->promotionMetadata($promotion);
        $type = (string) ($metadata['promotion_type'] ?? $metadata['type'] ?? $promotion->product_type ?? '');

        return in_array($type, self::DEFAULT_PROMOTION_TYPES, true) ? $type : 'social_media_mention';
    }

    private function promotionPlatform(Product $promotion): string
    {
        $metadata = $this->promotionMetadata($promotion);
        $platform = strtolower((string) ($metadata['platform'] ?? $metadata['platform_slug'] ?? ''));

        return in_array($platform, self::DEFAULT_PLATFORMS, true) ? $platform : 'other';
    }

    private function mapFrontendStatus(Product $promotion): string
    {
        $metadata = $this->promotionMetadata($promotion);
        $moderationStatus = (string) ($metadata['moderation_status'] ?? '');

        if ($moderationStatus === 'rejected') {
            return 'rejected';
        }

        return match ($promotion->status) {
            Product::STATUS_ACTIVE => 'active',
            Product::STATUS_ARCHIVED => 'paused',
            Product::STATUS_DRAFT => 'pending',
            default => 'pending',
        };
    }

    private function promotionMetadata(Product $promotion): array
    {
        return is_array($promotion->metadata) ? $promotion->metadata : [];
    }

    private function sellerOrderItemQuery(?User $user)
    {
        return OrderItem::query()
            ->with(['product.store.user', 'order.buyer'])
            ->whereHas('product', fn ($builder) => $builder->promotion())
            ->whereHas('product.store', fn ($storeQuery) => $storeQuery->where('user_id', $user?->id));
    }

    private function serializePromotionOrder(OrderItem $item): array
    {
        $order = $item->order;
        $promotion = $item->product;
        $metadata = $promotion ? $this->promotionMetadata($promotion) : [];
        $buyer = $order?->buyer;

        return [
            'id' => $item->id,
            'order_number' => $order?->order_number ?? 'ORD-'.($item->id ?? '0'),
            'status' => $this->promotionOrderStatus($item),
            'payment_status' => $order?->payment_status ?? Order::PAYMENT_PENDING,
            'payment_method' => $order?->payment_method ?? 'ugx',
            'credit_amount' => (int) ($order?->credit_amount ?? $order?->total_credits ?? 0),
            'ugx_amount' => (float) ($order?->total_ugx ?? $order?->total_amount ?? 0),
            'total_credits' => (int) ($order?->total_credits ?? 0),
            'total_ugx' => (float) ($order?->total_ugx ?? $order?->total_amount ?? 0),
            'promotion' => $promotion ? $this->serializePromotionListItem($promotion) : null,
            'song' => null,
            'buyer' => [
                'id' => $buyer?->id ?? 0,
                'name' => $buyer?->display_name ?: $buyer?->name ?: 'Unknown Buyer',
                'username' => $buyer?->username ?? 'unknown',
                'avatar_url' => $this->resolveAvatarUrl($buyer?->avatar),
                'is_verified' => (bool) ($buyer?->is_verified || $buyer?->email_verified_at),
                'follower_count' => (int) ($buyer?->followers()->count() ?? 0),
            ],
            'notes' => $order?->customer_notes,
            'settlement' => $this->promotionSettlementSummary($item),
            'verification' => [
                'status' => $item->verification_status ?? 'pending',
                'submitted_at' => optional($item->verification_submitted_at ?? null)->toIso8601String(),
                'verified_at' => optional($item->verified_at ?? null)->toIso8601String(),
                'verification_url' => $item->verification_url ?? null,
                'verification_notes' => $item->verification_notes ?? null,
                'verification_files' => $this->normalizeArrayField($item->verification_proof ?? null),
                'rejection_reason' => $item->rejection_reason ?? null,
            ],
            'dispute' => [
                'is_disputed' => ! empty($item->dispute_reason),
                'dispute_reason' => $item->dispute_reason ?? null,
                'reason' => $item->dispute_reason ?? null,
                'disputed_at' => optional($item->updated_at ?? null)->toIso8601String(),
                'created_at' => optional($item->created_at ?? null)->toIso8601String(),
                'resolved_at' => optional($item->verified_at ?? null)->toIso8601String(),
                'resolution' => null,
                'resolution_notes' => null,
            ],
            'created_at' => optional($item->created_at ?? null)->toIso8601String(),
            'expected_delivery_at' => optional($order?->created_at?->copy()->addDays((int) ($metadata['delivery_days_max'] ?? 3)))->toIso8601String(),
            'completed_at' => optional($order?->completed_at ?? null)->toIso8601String(),
        ];
    }

    private function promotionSettlementSummary(OrderItem $item): array
    {
        $snapshot = is_array($item->product_snapshot ?? null) ? $item->product_snapshot : [];
        $settlement = is_array($snapshot['promotion_settlement'] ?? null) ? $snapshot['promotion_settlement'] : [];

        return [
            'status' => $settlement['status'] ?? 'pending',
            'breakdown' => $settlement['breakdown'] ?? [
                'commission_rate' => 0,
                'gross_credits' => 0,
                'gross_ugx' => 0,
                'platform_fee_credits' => 0,
                'platform_fee_ugx' => 0,
                'seller_net_credits' => 0,
                'seller_net_ugx' => 0,
            ],
            'settled_at' => $settlement['settled_at'] ?? null,
            'reversed_at' => $settlement['reversed_at'] ?? null,
            'reversal_reason' => $settlement['reversal_reason'] ?? null,
        ];
    }

    private function promotionOrderStatus(OrderItem $item): string
    {
        if (! empty($item->dispute_reason)) {
            return 'disputed';
        }

        if ($item->verification_status === 'submitted') {
            return 'verification_submitted';
        }

        if ($item->verification_status === 'verified' || $item->order?->status === Order::STATUS_COMPLETED) {
            return 'completed';
        }

        if ($item->verification_status === 'rejected' || $item->order?->status === Order::STATUS_REFUNDED) {
            return 'refunded';
        }

        return 'pending_verification';
    }

    private function assertOwnsPromotion(Request $request, Product $promotion): void
    {
        $userId = $request->user()?->id;
        $ownsPromotion = $promotion->store?->user_id === $userId;

        if (! $ownsPromotion) {
            abort(404);
        }
    }

    private function normalizeArrayField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [$value];
        }

        return [];
    }

    private function resolveAvatarUrl(?string $avatar): ?string
    {
        return $this->resolveMediaUrl($avatar);
    }

    private function resolveMediaUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return url(ltrim($value, '/'));
    }

    private function promoterSocialLinks(User $user): array
    {
        return [
            'instagram_url' => $user->instagram_url ?? null,
            'twitter_url' => $user->twitter_url ?? null,
            'facebook_url' => $user->facebook_url ?? null,
            'youtube_url' => $user->youtube_url ?? null,
            'tiktok_url' => $user->tiktok_url ?? null,
            'website_url' => $user->settings['website_url'] ?? null,
        ];
    }
}
