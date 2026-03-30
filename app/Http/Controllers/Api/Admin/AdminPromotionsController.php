<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AdminPromotionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = trim((string) $request->input('status', ''));
        $search = trim((string) $request->input('search', ''));

        $query = Product::query()
            ->promotion()
            ->with(['store.user'])
            ->withCount($this->promotionCountRelations())
            ->when($status !== '', function ($builder) use ($status) {
                $builder->where(function ($inner) use ($status) {
                    if ($status === 'pending') {
                        $inner->where('status', Product::STATUS_DRAFT);
                    } else {
                        $inner->where('status', $status);
                    }
                });
            })
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('store.user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => collect($query->items())->map(fn (Product $promotion) => $this->serializePromotionListItem($promotion))->values(),
            'meta' => [
                'current_page' => $query->currentPage(),
                'total' => $query->total(),
                'per_page' => $query->perPage(),
                'last_page' => $query->lastPage(),
                'from' => $query->firstItem(),
                'to' => $query->lastItem(),
            ],
        ]);
    }

    public function approve(Request $request, int $promotion): JsonResponse
    {
        $listing = Product::query()
            ->promotion()
            ->with('store.user')
            ->findOrFail($promotion);

        $metadata = is_array($listing->metadata ?? null) ? $listing->metadata : [];
        $metadata['moderation'] = array_merge((array) data_get($metadata, 'moderation', []), [
            'status' => Product::STATUS_ACTIVE,
            'approved_at' => now()->toIso8601String(),
            'approved_by' => $request->user()?->id,
            'rejected_at' => null,
            'rejected_by' => null,
            'reason' => null,
        ]);

        $listing->forceFill([
            'status' => Product::STATUS_ACTIVE,
            'published_at' => $listing->published_at ?? now(),
            'metadata' => $metadata,
        ])->save();

        $this->logPromotionModeration($request, 'promotion_moderation_approved', $listing, [
            'status' => $listing->status,
            'promotion_slug' => $listing->slug,
            'requested_by_user_id' => $listing->store?->user_id,
        ]);

        return response()->json([
            'success' => true,
            'status' => $this->normalizePromotionStatus($listing->status),
            'data' => $this->serializePromotionListItem($listing->fresh(['store.user'])),
        ]);
    }

    public function reject(Request $request, int $promotion): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $listing = Product::query()
            ->promotion()
            ->with('store.user')
            ->findOrFail($promotion);

        $metadata = is_array($listing->metadata ?? null) ? $listing->metadata : [];
        $metadata['moderation'] = array_merge((array) data_get($metadata, 'moderation', []), [
            'status' => 'rejected',
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => now()->toIso8601String(),
            'rejected_by' => $request->user()?->id,
            'reason' => $validated['reason'],
        ]);

        $listing->forceFill([
            'status' => 'rejected',
            'metadata' => $metadata,
        ])->save();

        $this->logPromotionModeration($request, 'promotion_moderation_rejected', $listing, [
            'status' => $listing->status,
            'promotion_slug' => $listing->slug,
            'requested_by_user_id' => $listing->store?->user_id,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'success' => true,
            'status' => $this->normalizePromotionStatus($listing->status),
            'data' => $this->serializePromotionListItem($listing->fresh(['store.user'])),
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
            ])
            ->get();

        $orders = Order::query()
            ->whereHas('items.product', fn ($query) => $query->promotion())
            ->with(['items.product.store.user'])
            ->get();

        $disputedOrders = $orders->filter(fn (Order $order) => $this->firstPromotionItem($order)?->dispute_reason);

        $topPromoters = $promotions
            ->groupBy('store.user_id')
            ->map(function (Collection $items) {
                /** @var Product|null $first */
                $first = $items->first();
                $user = $first?->store?->user;

                if (! $user) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'avatar_url' => $user->avatar_url ?? $user->avatar ?? null,
                    'is_verified' => (bool) ($user->is_verified ?? false),
                    'follower_count' => (int) ($user->followers_count ?? 0),
                    'active_promotions' => $items->filter(fn (Product $product) => $product->status === Product::STATUS_ACTIVE)->count(),
                    'total_orders' => (int) $items->sum('total_orders'),
                    'total_revenue_credits' => (float) $items->sum('price_credits'),
                    'avg_rating' => $items->avg('average_rating'),
                ];
            })
            ->filter()
            ->sortByDesc('total_orders')
            ->values()
            ->take(5)
            ->all();

        $topTypes = $promotions
            ->groupBy(fn (Product $promotion) => (string) data_get($promotion->metadata ?? [], 'promotion_type', 'social_media_mention'))
            ->map(fn (Collection $items, string $type) => [
                'type' => $type,
                'count' => $items->count(),
                'revenue' => (float) $items->sum('price_credits'),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(5)
            ->all();

        $platformBreakdown = $promotions
            ->groupBy(fn (Product $promotion) => (string) data_get($promotion->metadata ?? [], 'platform', 'other'))
            ->map(fn (Collection $items, string $platform) => [
                'platform' => $platform,
                'count' => $items->count(),
                'orders' => (int) $items->sum('total_orders'),
                'completed_orders' => (int) $items->sum('completed_orders'),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $disputePlatformBreakdown = $disputedOrders
            ->groupBy(function (Order $order) {
                $item = $this->firstPromotionItem($order);

                return (string) data_get($item?->product?->metadata ?? [], 'platform', 'other');
            })
            ->map(fn (Collection $items, string $platform) => [
                'platform' => $platform,
                'count' => $items->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $proofCoverageCount = $promotions->filter(fn (Product $promotion) => filled($promotion->featured_image))->count();
        $targetingCoverageCount = $promotions->filter(function (Product $promotion) {
            $metadata = is_array($promotion->metadata ?? null) ? $promotion->metadata : [];

            return ! empty(data_get($metadata, 'audience_niches'))
                && ! empty(data_get($metadata, 'content_formats'));
        })->count();

        $refundedOrders = $orders->filter(fn (Order $order) => $order->payment_status === Order::PAYMENT_REFUNDED);
        $repeatBuyerOrders = $orders
            ->groupBy('user_id')
            ->filter(fn (Collection $buyerOrders, $buyerId) => $buyerId && $buyerOrders->count() > 1)
            ->flatten(1);

        $proofSubmissionHours = $orders
            ->flatMap(function (Order $order) {
                return $order->items->map(function ($item) use ($order) {
                    if (! $item->verification_submitted_at || ! $order->created_at) {
                        return null;
                    }

                    return (float) $order->created_at->diffInMinutes($item->verification_submitted_at) / 60;
                });
            })
            ->filter(fn ($value) => $value !== null)
            ->values();

        $disputeResolutionHours = $disputedOrders
            ->map(function (Order $order) {
                $item = $this->firstPromotionItem($order);
                $snapshot = is_array($item?->product_snapshot ?? null) ? $item->product_snapshot : [];
                $createdAt = data_get($snapshot, 'promotion_dispute.created_at');
                $resolvedAt = data_get($snapshot, 'promotion_dispute.resolved_at');

                if (! $createdAt || ! $resolvedAt) {
                    return null;
                }

                return (float) \Carbon\Carbon::parse($createdAt)->diffInMinutes(\Carbon\Carbon::parse($resolvedAt)) / 60;
            })
            ->filter(fn ($value) => $value !== null)
            ->values();

        return response()->json([
            'data' => [
                'total_promotions' => $promotions->count(),
                'active_promotions' => $promotions->where('status', Product::STATUS_ACTIVE)->count(),
                'total_orders' => $orders->count(),
                'total_gmv_credits' => (float) $orders->sum(fn (Order $order) => (float) ($order->total_credits ?? 0)),
                'total_gmv_ugx' => (float) $orders->sum(fn (Order $order) => (float) ($order->total_ugx ?? 0)),
                'platform_revenue_ugx' => (float) $orders->sum(fn (Order $order) => (float) ($order->platform_fee_ugx ?? 0)),
                'top_promoters' => $topPromoters,
                'top_promotion_types' => $topTypes,
                'platform_breakdown' => $platformBreakdown,
                'dispute_platform_breakdown' => $disputePlatformBreakdown,
                'proof_coverage_pct' => $promotions->count() > 0 ? round(($proofCoverageCount / $promotions->count()) * 100, 2) : 0,
                'targeting_coverage_pct' => $promotions->count() > 0 ? round(($targetingCoverageCount / $promotions->count()) * 100, 2) : 0,
                'refund_rate' => $orders->count() > 0 ? round($refundedOrders->count() / $orders->count(), 4) : 0,
                'repeat_buyer_rate' => $orders->count() > 0 ? round($repeatBuyerOrders->count() / $orders->count(), 4) : 0,
                'avg_proof_submission_hours' => $proofSubmissionHours->isNotEmpty() ? round((float) $proofSubmissionHours->avg(), 2) : null,
                'avg_dispute_resolution_hours' => $disputeResolutionHours->isNotEmpty() ? round((float) $disputeResolutionHours->avg(), 2) : null,
                'average_order_value' => $orders->count() > 0 ? round((float) $orders->avg('total_ugx'), 2) : 0,
                'dispute_rate' => $orders->count() > 0 ? round($disputedOrders->count() / $orders->count(), 4) : 0,
                'pending_promotions' => $promotions->where('status', Product::STATUS_DRAFT)->count(),
            ],
        ]);
    }

    public function disputes(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = trim((string) $request->input('status', 'open'));

        $orders = Order::query()
            ->whereHas('items.product', fn ($query) => $query->promotion())
            ->whereHas('items', fn ($query) => $query->whereNotNull('dispute_reason'))
            ->with(['buyer', 'items.product.store.user'])
            ->latest()
            ->get()
            ->filter(function (Order $order) use ($status) {
                $orderItem = $this->firstPromotionItem($order);
                $isResolved = ! empty(data_get($orderItem?->product_snapshot ?? [], 'promotion_dispute.resolution'));

                return $status === 'resolved' ? $isResolved : ! $isResolved;
            })
            ->values();

        $page = max(1, (int) $request->integer('page', 1));
        $slice = $orders->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $slice->map(fn (Order $order) => $this->serializeDisputeOrder($order))->values(),
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

    public function resolveDispute(Request $request, int $disputeId): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => 'required|in:refund_buyer,release_to_seller',
            'notes' => 'nullable|string|max:2000',
        ]);

        $order = Order::query()
            ->where('id', $disputeId)
            ->whereHas('items.product', fn ($query) => $query->promotion())
            ->with(['buyer', 'items.product.store.user'])
            ->firstOrFail();

        $orderItem = $this->firstPromotionItem($order);
        if (! $orderItem || ! $orderItem->dispute_reason) {
            return response()->json([
                'message' => 'Promotion dispute not found.',
            ], 404);
        }

        $snapshot = is_array($orderItem->product_snapshot ?? null) ? $orderItem->product_snapshot : [];
        $existingDispute = (array) data_get($snapshot, 'promotion_dispute', []);
        if (($existingDispute['state'] ?? null) === 'resolved') {
            return response()->json([
                'message' => 'This promotion dispute has already been resolved.',
            ], 422);
        }

        $resolution = $validated['resolution'];
        $notes = $validated['notes'] ?? null;
        $settlementService = app(PromotionSettlementService::class);

        DB::transaction(function () use ($request, $order, $orderItem, $resolution, $notes, $settlementService) {
            $settlement = $resolution === 'refund_buyer'
                ? $settlementService->reverseOrder($order, $orderItem, $notes ?: 'Resolved by admin dispute workflow.')
                : $settlementService->settleOrder($order, $orderItem);

            $snapshot = is_array($orderItem->product_snapshot ?? null) ? $orderItem->product_snapshot : [];
            $snapshot['promotion_dispute'] = [
                'reason' => $orderItem->dispute_reason,
                'reason_code' => data_get($snapshot, 'promotion_dispute.reason_code'),
                'state' => 'resolved',
                'created_at' => data_get($snapshot, 'promotion_dispute.created_at'),
                'created_by' => data_get($snapshot, 'promotion_dispute.created_by'),
                'evidence_url' => data_get($snapshot, 'promotion_dispute.evidence_url'),
                'evidence_files' => array_values(array_filter((array) data_get($snapshot, 'promotion_dispute.evidence_files', []))),
                'resolution' => $resolution,
                'admin_notes' => $notes,
                'resolved_at' => now()->toIso8601String(),
                'resolved_by' => $request->user()?->id,
                'settlement' => $settlement,
            ];

            $orderItem->forceFill([
                'product_snapshot' => $snapshot,
                'verification_status' => $resolution === 'release_to_seller' ? 'verified' : 'rejected',
                'rejection_reason' => $resolution === 'refund_buyer' ? ($notes ?: $orderItem->dispute_reason) : null,
            ])->save();

            $order->forceFill([
                'status' => $resolution === 'release_to_seller' ? Order::STATUS_COMPLETED : Order::STATUS_CANCELLED,
                'payment_status' => $resolution === 'release_to_seller' ? Order::PAYMENT_PAID : Order::PAYMENT_REFUNDED,
                'completed_at' => $resolution === 'release_to_seller' ? now() : $order->completed_at,
                'refunded_at' => $resolution === 'refund_buyer' ? now() : $order->refunded_at,
                'refund_reason' => $resolution === 'refund_buyer' ? ($notes ?: $orderItem->dispute_reason) : $order->refund_reason,
                'admin_notes' => $notes,
            ])->save();
        });

        $this->logPromotionModeration($request, 'promotion_dispute_resolved', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'resolution' => $resolution,
            'notes' => $notes,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeDisputeOrder($order->fresh(['buyer', 'items.product.store.user'])),
        ]);
    }

    private function serializePromotionListItem(Product $promotion): array
    {
        $promotion->loadMissing(['store.user']);
        $metadata = is_array($promotion->metadata ?? null) ? $promotion->metadata : [];
        $promoter = $promotion->store?->user;

        return [
            'id' => $promotion->id,
            'slug' => $promotion->slug,
            'title' => $promotion->name,
            'short_description' => $promotion->short_description ?: Str::limit((string) $promotion->description, 120),
            'type' => (string) data_get($metadata, 'promotion_type', 'social_media_mention'),
            'platform' => (string) data_get($metadata, 'platform', 'other'),
            'price_credits' => (float) ($promotion->price_credits ?? 0),
            'price_ugx' => (float) ($promotion->price_ugx ?? 0),
            'accepts_credits' => (bool) ($promotion->allow_credit_payment || $promotion->accepts_credits),
            'accepts_ugx' => (float) ($promotion->price_ugx ?? 0) > 0,
            'accepts_hybrid' => (bool) $promotion->allow_hybrid_payment,
            'estimated_reach' => (int) data_get($metadata, 'estimated_reach', 0),
            'audience_niches' => array_values(array_filter((array) data_get($metadata, 'audience_niches', []))),
            'audience_regions' => array_values(array_filter((array) data_get($metadata, 'audience_regions', []))),
            'content_formats' => array_values(array_filter((array) data_get($metadata, 'content_formats', []))),
            'delivery_days_min' => (int) data_get($metadata, 'delivery_days_min', 1),
            'delivery_days_max' => (int) data_get($metadata, 'delivery_days_max', 7),
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
            'featured_image_url' => $promotion->featured_image_url,
            'status' => $this->normalizePromotionStatus((string) $promotion->status),
            'created_at' => optional($promotion->created_at)->toIso8601String(),
            'promoter' => $promoter ? $this->serializeUserSummary($promoter) : [
                'id' => 0,
                'name' => 'Unknown Promoter',
                'username' => 'unknown',
                'avatar_url' => null,
                'is_verified' => false,
                'follower_count' => 0,
            ],
        ];
    }

    private function serializeDisputeOrder(Order $order): array
    {
        $order->loadMissing(['buyer', 'items.product.store.user']);
        $orderItem = $this->firstPromotionItem($order);
        $promotion = $orderItem?->product;
        $snapshot = is_array($orderItem?->product_snapshot ?? null) ? $orderItem->product_snapshot : [];
        $disputeMeta = (array) data_get($snapshot, 'promotion_dispute', []);
        $resolution = data_get($disputeMeta, 'resolution');

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $resolution ? ($resolution === 'refund_buyer' ? 'refunded' : 'completed') : 'disputed',
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'credit_amount' => (int) ($order->credit_amount ?? 0),
            'ugx_amount' => (float) ($order->total_ugx ?? 0),
            'total_credits' => (int) ($order->total_credits ?? 0),
            'total_ugx' => (float) ($order->total_ugx ?? 0),
            'promotion' => $promotion ? $this->serializePromotionListItem($promotion) : null,
            'song' => null,
            'buyer' => $order->buyer ? $this->serializeUserSummary($order->buyer) : null,
            'notes' => $order->customer_notes,
            'verification' => [
                'status' => $orderItem?->verification_status ?? 'pending',
                'submitted_at' => optional($orderItem?->verification_submitted_at)->toIso8601String(),
                'verified_at' => optional($orderItem?->verified_at)->toIso8601String(),
                'verification_url' => $orderItem?->verification_url,
                'verification_notes' => $orderItem?->verification_notes,
                'verification_files' => is_array($orderItem?->verification_proof ?? null) ? $orderItem->verification_proof : [],
                'rejection_reason' => $orderItem?->rejection_reason,
            ],
            'dispute' => [
                'is_disputed' => ! empty($orderItem?->dispute_reason),
                'state' => ! empty($orderItem?->dispute_reason)
                    ? (data_get($disputeMeta, 'state') ?: ($resolution ? 'resolved' : 'open'))
                    : null,
                'reason_code' => data_get($disputeMeta, 'reason_code'),
                'dispute_reason' => $orderItem?->dispute_reason,
                'reason' => $orderItem?->dispute_reason,
                'disputed_at' => data_get($disputeMeta, 'created_at') ?? optional($orderItem?->updated_at)->toIso8601String(),
                'created_at' => optional($order->created_at)->toIso8601String(),
                'resolved_at' => data_get($disputeMeta, 'resolved_at'),
                'resolution' => $resolution,
                'resolution_notes' => data_get($disputeMeta, 'admin_notes'),
                'admin_notes' => data_get($disputeMeta, 'admin_notes'),
                'evidence_url' => data_get($disputeMeta, 'evidence_url') ?? $orderItem?->verification_url,
                'evidence_files' => array_values(array_filter((array) data_get($disputeMeta, 'evidence_files', []))),
                'settlement_status' => data_get($disputeMeta, 'settlement.status'),
                'refund_reason' => $order->refund_reason,
            ],
            'created_at' => optional($order->created_at)->toIso8601String(),
            'expected_delivery_at' => optional($order->created_at?->copy()->addDays(3))->toIso8601String(),
            'completed_at' => optional($order->completed_at)->toIso8601String(),
        ];
    }

    private function firstPromotionItem(Order $order): ?OrderItem
    {
        return $order->items->first(fn (OrderItem $item) => $item->product?->product_type === Product::TYPE_PROMOTION);
    }

    private function normalizePromotionStatus(string $status): string
    {
        return $status === Product::STATUS_DRAFT ? 'pending' : $status;
    }

    private function promotionCountRelations(): array
    {
        $counts = [
            'orderItems as total_orders',
            'orderItems as completed_orders' => fn ($builder) => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_COMPLETED)),
        ];

        if ($this->reviewsTableAvailable()) {
            $counts['approvedGenericReviews as rating_count'] = fn ($builder) => $builder;
        }

        return $counts;
    }

    private function reviewsTableAvailable(): bool
    {
        static $available;

        if ($available === null) {
            $available = Schema::hasTable('reviews');
        }

        return $available;
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

    private function logPromotionModeration(Request $request, string $action, $auditable, array $data = []): void
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
            // Moderation should continue even if the audit record cannot be written.
        }
    }
}
