<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminPromotionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = strtolower(trim((string) $request->input('status', '')));
        $search = trim((string) $request->input('search', ''));

        $query = Product::query()
            ->promotion()
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedReviews as rating_count',
            ])
            ->when($status !== '' && $status !== 'all', function ($builder) use ($status) {
                match ($status) {
                    'pending' => $builder->where('status', Product::STATUS_DRAFT),
                    'active' => $builder->where('status', Product::STATUS_ACTIVE),
                    'paused' => $builder->where('status', Product::STATUS_ARCHIVED)->where(function ($inner) {
                        $inner->whereNull('metadata')
                            ->orWhereRaw("JSON_EXTRACT(metadata, '$.moderation_status') IS NULL")
                            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.moderation_status')) <> 'rejected'");
                    }),
                    'rejected' => $builder->where('status', Product::STATUS_ARCHIVED)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.moderation_status')) = 'rejected'"),
                    default => $builder->where('status', $status),
                };
            })
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('store', fn ($storeQuery) => $storeQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('store.user', fn ($userQuery) => $userQuery->where('username', 'like', "%{$search}%")
                            ->orWhere('display_name', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at');

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

    public function approve(Request $request, Product $promotion): JsonResponse
    {
        $promotion->loadMissing(['store.user']);
        $metadata = $this->promotionMetadata($promotion);
        $metadata['moderation_status'] = 'approved';
        $metadata['moderated_at'] = now()->toIso8601String();
        $metadata['moderated_by'] = optional($request->user())->id;
        unset($metadata['moderation_reason']);

        $promotion->update([
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now(),
            'metadata' => $metadata,
        ]);

        return response()->json([
            'success' => true,
            'status' => 'active',
            'data' => $this->serializePromotionListItem($promotion->fresh(['store.user'])),
        ]);
    }

    public function reject(Request $request, Product $promotion): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $promotion->loadMissing(['store.user']);
        $metadata = $this->promotionMetadata($promotion);
        $metadata['moderation_status'] = 'rejected';
        $metadata['moderation_reason'] = $validated['reason'];
        $metadata['moderated_at'] = now()->toIso8601String();
        $metadata['moderated_by'] = optional($request->user())->id;

        $promotion->update([
            'status' => Product::STATUS_ARCHIVED,
            'metadata' => $metadata,
        ]);

        return response()->json([
            'success' => true,
            'status' => 'rejected',
            'data' => $this->serializePromotionListItem($promotion->fresh(['store.user'])),
        ]);
    }

    public function analytics(): JsonResponse
    {
        $promotions = Product::query()
            ->promotion()
            ->with(['store.user'])
            ->withCount([
                'orderItems as total_orders',
                'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
                'approvedReviews as rating_count',
            ])
            ->get();

        $completedOrderItems = OrderItem::query()
            ->with(['product.store', 'order'])
            ->whereHas('product', fn ($builder) => $builder->promotion())
            ->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED))
            ->get();

        $gmvUgx = (float) $completedOrderItems->sum(fn (OrderItem $item) => (float) ($item->price_ugx ?? 0) * (int) ($item->quantity ?? 1));
        $gmvCredits = (float) $completedOrderItems->sum(fn (OrderItem $item) => (int) ($item->price_credits ?? 0) * (int) ($item->quantity ?? 1));

        $platformRevenueUgx = (float) $completedOrderItems->sum(function (OrderItem $item) {
            $store = $item->product?->store;

            return $store ? $store->calculatePromotionFee((float) ($item->price_ugx ?? 0) * (int) ($item->quantity ?? 1)) : 0;
        });

        $topPromoters = $promotions
            ->groupBy('store_id')
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(5)
            ->map(function (Collection $group) {
                $promotion = $group->first();
                $user = $promotion?->store?->user;

                return [
                    'id' => $user?->id ?? 0,
                    'name' => $user?->display_name ?: $user?->name ?: 'Unknown Promoter',
                    'username' => $user?->username ?? 'unknown',
                    'avatar_url' => $this->resolveAvatarUrl($user?->avatar),
                    'is_verified' => (bool) ($user?->is_verified || $user?->email_verified_at),
                    'follower_count' => (int) ($user?->followers()->count() ?? 0),
                ];
            })
            ->values();

        $typeSummary = $promotions
            ->groupBy(fn (Product $promotion) => $this->promotionType($promotion))
            ->map(function (Collection $group, string $type) {
                $revenue = $group->sum(fn (Product $promotion) => (float) ($promotion->price_ugx ?? 0) * max((int) ($promotion->completed_orders ?? 0), 1));

                return [
                    'type' => $type,
                    'count' => $group->count(),
                    'revenue' => round($revenue, 2),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();

        $total = $promotions->count();
        $active = $promotions->where('status', Product::STATUS_ACTIVE)->count();
        $pending = $promotions->where('status', Product::STATUS_DRAFT)->count();
        $rejected = $promotions->filter(function (Product $promotion) {
            return (($this->promotionMetadata($promotion)['moderation_status'] ?? null) === 'rejected');
        })->count();

        return response()->json([
            'data' => [
                'total_promotions' => $total,
                'active_promotions' => $active,
                'total_orders' => $completedOrderItems->count(),
                'total_gmv_credits' => $gmvCredits,
                'total_gmv_ugx' => $gmvUgx,
                'platform_revenue_ugx' => round($platformRevenueUgx, 2),
                'top_promoters' => $topPromoters,
                'top_promotion_types' => $typeSummary,
                'average_order_value' => $completedOrderItems->count() > 0 ? round($gmvUgx / $completedOrderItems->count(), 2) : 0,
                'dispute_rate' => $total > 0 ? round($rejected / $total, 4) : 0,
                'pending_promotions' => $pending,
            ],
        ]);
    }

    public function disputes(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = strtolower(trim((string) $request->input('status', '')));

        $query = OrderItem::query()
            ->with(['product.store.user', 'order.buyer'])
            ->whereHas('product', fn ($builder) => $builder->promotion())
            ->where(function ($builder) {
                $builder->whereNotNull('dispute_reason')
                    ->orWhere('verification_status', 'disputed');
            })
            ->when($status !== '' && $status !== 'all', fn ($builder) => $builder->where('verification_status', $status))
            ->latest();

        $disputes = $query->paginate($perPage);

        return response()->json([
            'data' => collect($disputes->items())
                ->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'order_number' => $item->order?->order_number,
                    'promotion' => $item->product ? $this->serializePromotionListItem($item->product) : null,
                    'buyer' => $item->order?->buyer ? [
                        'id' => $item->order->buyer->id,
                        'name' => $item->order->buyer->display_name ?: $item->order->buyer->name,
                        'username' => $item->order->buyer->username,
                        'avatar_url' => $this->resolveAvatarUrl($item->order->buyer->avatar),
                        'is_verified' => (bool) ($item->order->buyer->is_verified || $item->order->buyer->email_verified_at),
                        'follower_count' => (int) ($item->order->buyer->followers()->count() ?? 0),
                    ] : null,
                    'reason' => $item->dispute_reason ?: $item->verification_notes,
                    'status' => $item->verification_status ?: ($item->dispute_reason ? 'disputed' : 'pending'),
                    'created_at' => optional($item->created_at)->toIso8601String(),
                    'resolved_at' => optional($item->verified_at)->toIso8601String(),
                ])
                ->values(),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'total' => $disputes->total(),
                'per_page' => $disputes->perPage(),
                'last_page' => $disputes->lastPage(),
                'from' => $disputes->firstItem(),
                'to' => $disputes->lastItem(),
            ],
        ]);
    }

    public function resolveDispute(Request $request, int $disputeId): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => 'required|string|in:refund_buyer,release_to_seller',
            'notes' => 'nullable|string|max:2000',
        ]);

        $dispute = OrderItem::findOrFail($disputeId);
        $dispute->update([
            'dispute_reason' => null,
            'verification_notes' => trim(($validated['notes'] ?? '') . ' Resolution: ' . $validated['resolution']),
        ]);

        return response()->json([
            'success' => true,
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

    private function promotionType(Product $promotion): string
    {
        $metadata = $this->promotionMetadata($promotion);
        $type = (string) ($metadata['promotion_type'] ?? $metadata['type'] ?? $promotion->product_type ?? '');

        return in_array($type, [
            'social_media_mention',
            'live_stream_promotion',
            'radio_mention',
            'dj_shoutout',
            'ticket_giveaway',
            'content_creation',
            'playlist_inclusion',
            'collaboration_offer',
        ], true) ? $type : 'social_media_mention';
    }

    private function promotionPlatform(Product $promotion): string
    {
        $metadata = $this->promotionMetadata($promotion);
        $platform = strtolower((string) ($metadata['platform'] ?? $metadata['platform_slug'] ?? ''));

        return in_array($platform, [
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
        ], true) ? $platform : 'other';
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

    private function resolveAvatarUrl(?string $avatar): ?string
    {
        if (! $avatar) {
            return null;
        }

        if (filter_var($avatar, FILTER_VALIDATE_URL)) {
            return $avatar;
        }

        return url(ltrim($avatar, '/'));
    }
}
