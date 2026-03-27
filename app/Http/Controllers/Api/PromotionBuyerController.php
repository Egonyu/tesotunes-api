<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\StoreReview;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionBuyerController extends Controller
{
    public function purchase(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => 'required|string|in:credits,ugx,hybrid',
            'credits_amount' => 'nullable|integer|min:0',
            'ugx_amount' => 'nullable|numeric|min:0',
            'song_id' => 'nullable|integer|exists:songs,id',
            'event_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            'preferred_delivery_date' => 'nullable|date',
        ]);

        $user = $request->user();
        $promotion = Product::query()
            ->promotion()
            ->active()
            ->with(['store.user'])
            ->where('slug', $slug)
            ->firstOrFail();

        $song = ! empty($validated['song_id'])
            ? Song::query()->with('artist')->findOrFail($validated['song_id'])
            : null;

        $order = DB::transaction(function () use ($user, $promotion, $validated, $song) {
            $totalCredits = (int) ($validated['credits_amount'] ?? (($validated['payment_method'] === 'credits' || $validated['payment_method'] === 'hybrid') ? (int) ($promotion->price_credits ?? 0) : 0));
            $totalUgx = (float) ($validated['ugx_amount'] ?? (($validated['payment_method'] === 'ugx' || $validated['payment_method'] === 'hybrid') ? (float) ($promotion->price_ugx ?? 0) : 0));

            $order = Order::create([
                'store_id' => $promotion->store_id,
                'user_id' => $user->id,
                'status' => 'pending_verification',
                'payment_status' => Order::PAYMENT_PAID,
                'payment_method' => $validated['payment_method'],
                'credit_amount' => $totalCredits,
                'subtotal' => $totalUgx ?: $totalCredits,
                'tax_amount' => 0,
                'shipping_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $totalUgx ?: $totalCredits,
                'subtotal_ugx' => $totalUgx,
                'subtotal_credits' => $totalCredits,
                'total_ugx' => $totalUgx,
                'total_credits' => $totalCredits,
                'paid_ugx' => $totalUgx,
                'paid_credits' => $totalCredits,
                'customer_notes' => $validated['notes'] ?? null,
                'paid_at' => now(),
            ]);

            $settlement = app(PromotionSettlementService::class)->buildBreakdown($order, $promotion, $promotion->store?->user);
            $order->update([
                'platform_fee_ugx' => $settlement['platform_fee_ugx'],
                'platform_fee_credits' => $settlement['platform_fee_credits'],
            ]);

            $itemData = OrderItem::createFromProduct($promotion, 1);
            if ($song) {
                $itemData['product_snapshot']['song'] = $this->serializeSong($song);
            }

            $itemData['product_snapshot']['promotion_settlement'] = [
                'status' => 'pending',
                'breakdown' => $settlement,
            ];
            $itemData['verification_status'] = 'pending';
            $itemData['verification_notes'] = $validated['notes'] ?? null;
            $itemData['verification_submitted_at'] = null;
            $itemData['verification_proof'] = [];

            $order->items()->create($itemData);

            return $order->load(['items.product.store.user', 'buyer']);
        });

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => 'pending_verification',
            'payment_status' => $order->payment_status,
            'total_credits' => (int) $order->total_credits,
            'total_ugx' => (float) $order->total_ugx,
            'created_at' => optional($order->created_at)->toIso8601String(),
        ], 201);
    }

    public function myPurchases(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status = strtolower(trim((string) $request->input('status', '')));

        $query = $this->buyerOrderQuery($request->user());

        if ($status !== '' && $status !== 'all') {
            $query->whereHas('items', function ($itemQuery) use ($status) {
                $itemQuery->where(function ($builder) use ($status) {
                    match ($status) {
                        'pending_verification' => $builder->where(function ($inner) {
                            $inner->whereNull('verification_status')->orWhere('verification_status', 'pending');
                        })->where('dispute_reason', null),
                        'verification_submitted' => $builder->where('verification_status', 'submitted'),
                        'completed' => $builder->where('verification_status', 'verified'),
                        'disputed' => $builder->whereNotNull('dispute_reason'),
                        'refunded' => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_REFUNDED)),
                        'cancelled' => $builder->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', Order::STATUS_CANCELLED)),
                        default => $builder,
                    };
                });
            });
        }

        $orders = $query->latest()->paginate($perPage);

        return response()->json([
            'data' => collect($orders->items())
                ->map(fn (Order $order) => $this->serializeBuyerOrder($order))
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

    public function myPurchase(Request $request, int $orderId): JsonResponse
    {
        $order = $this->buyerOrderQuery($request->user())->findOrFail($orderId);

        return response()->json([
            'data' => $this->serializeBuyerOrder($order),
        ]);
    }

    public function submitVerification(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'verification_url' => 'required|string|max:2048',
            'verification_notes' => 'nullable|string|max:2000',
            'verification_files' => 'nullable|array',
            'verification_files.*' => 'nullable|string|max:2048',
        ]);

        $order = $this->buyerOrderQuery($request->user())->findOrFail($orderId);
        $item = $order->items()->firstOrFail();

        $item->update([
            'verification_status' => 'submitted',
            'verification_url' => $validated['verification_url'],
            'verification_notes' => $validated['verification_notes'] ?? null,
            'verification_proof' => $validated['verification_files'] ?? [],
            'verification_submitted_at' => now(),
        ]);

        $order->update([
            'status' => 'verification_submitted',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification submitted successfully.',
        ]);
    }

    public function dispute(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $order = $this->buyerOrderQuery($request->user())->findOrFail($orderId);
        $item = $order->items()->firstOrFail();

        $item->update([
            'dispute_reason' => $validated['reason'],
            'verification_status' => 'disputed',
        ]);

        $order->update([
            'status' => 'disputed',
        ]);

        app(PromotionSettlementService::class)->reverseOrder($order, $item, $validated['reason']);

        return response()->json([
            'success' => true,
            'dispute_id' => $item->id,
        ]);
    }

    public function review(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:2000',
            'would_recommend' => 'required|boolean',
        ]);

        $order = $this->buyerOrderQuery($request->user())->with(['items.product.store.user'])->findOrFail($orderId);
        $item = $order->items()->firstOrFail();
        $promotion = $item->product;

        $review = StoreReview::create([
            'store_id' => $promotion->store_id,
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'product_id' => $promotion->id,
            'rating' => $validated['rating'],
            'review' => $validated['comment'],
            'title' => Str::limit($validated['comment'], 50, ''),
            'status' => 'approved',
            'is_verified_purchase' => true,
        ]);

        $promotion->updateRating();

        return response()->json([
            'success' => true,
            'review_id' => $review->id,
        ]);
    }



    private function serializeBuyerOrder(Order $order): array
    {
        $item = $order->items->first();
        $promotion = $item?->product;
        $song = $item?->product_snapshot['song'] ?? null;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $this->promotionOrderStatus($item),
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'credit_amount' => (int) ($order->credit_amount ?? $order->total_credits ?? 0),
            'ugx_amount' => (float) ($order->total_ugx ?? $order->total_amount ?? 0),
            'total_credits' => (int) ($order->total_credits ?? 0),
            'total_ugx' => (float) ($order->total_ugx ?? $order->total_amount ?? 0),
            'promotion' => $promotion ? $this->serializePromotionListItem($promotion) : null,
            'song' => is_array($song) ? $song : null,
            'buyer' => [
                'id' => $order->buyer?->id ?? 0,
                'name' => $order->buyer?->display_name ?: $order->buyer?->name ?: 'Unknown Buyer',
                'username' => $order->buyer?->username ?? 'unknown',
                'avatar_url' => $this->resolveAvatarUrl($order->buyer?->avatar),
                'is_verified' => (bool) ($order->buyer?->is_verified || $order->buyer?->email_verified_at),
                'follower_count' => (int) ($order->buyer?->followers()->count() ?? 0),
            ],
            'notes' => $order->customer_notes,
            'verification' => [
                'status' => $item?->verification_status ?? 'pending',
                'submitted_at' => optional($item?->verification_submitted_at ?? null)->toIso8601String(),
                'verified_at' => optional($item?->verified_at ?? null)->toIso8601String(),
                'verification_url' => $item?->verification_url ?? null,
                'verification_notes' => $item?->verification_notes ?? null,
                'verification_files' => $this->normalizeArrayField($item?->verification_proof ?? null),
                'rejection_reason' => $item?->rejection_reason ?? null,
            ],
            'dispute' => [
                'is_disputed' => ! empty($item?->dispute_reason),
                'dispute_reason' => $item?->dispute_reason ?? null,
                'reason' => $item?->dispute_reason ?? null,
                'disputed_at' => optional($item?->updated_at ?? null)->toIso8601String(),
                'created_at' => optional($item?->created_at ?? null)->toIso8601String(),
                'resolved_at' => optional($item?->verified_at ?? null)->toIso8601String(),
                'resolution' => null,
                'resolution_notes' => null,
            ],
            'created_at' => optional($order->created_at)->toIso8601String(),
            'expected_delivery_at' => optional($order->created_at?->copy()->addDays((int) ($this->promotionMetadata($promotion)['delivery_days_max'] ?? 3)))->toIso8601String(),
            'completed_at' => optional($order->completed_at)->toIso8601String(),
        ];
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

    private function promotionOrderStatus(?OrderItem $item): string
    {
        if (! $item) {
            return 'pending_verification';
        }

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

        if ($item->order?->status === Order::STATUS_CANCELLED) {
            return 'cancelled';
        }

        return 'pending_verification';
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

    private function serializeSong(Song $song): array
    {
        return [
            'id' => $song->id,
            'title' => $song->title,
            'slug' => $song->slug,
            'artwork_url' => $song->artwork_url ?? ($song->artwork ? url($song->artwork) : null),
            'audio_url' => $song->audio_url ?? null,
            'artist' => $song->artist ? [
                'id' => $song->artist->id,
                'name' => $song->artist->stage_name ?: $song->artist->name ?: 'Unknown Artist',
                'slug' => $song->artist->slug ?? null,
            ] : null,
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
