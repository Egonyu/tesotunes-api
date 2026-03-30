<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Review;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Services\PaymentService;
use App\Services\Store\PromotionSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sort = $request->string('sort')->toString();
        $hasStructuredMatchFilters = $request->filled('type')
            || $request->filled('platform')
            || $request->filled('audience_niche')
            || $request->filled('audience_region')
            || $request->filled('content_format')
            || $request->filled('channel')
            || $request->filled('placement')
            || $request->filled('proof_type')
            || $request->filled('timing')
            || $request->filled('delivery_days_max')
            || $request->filled('rating_min')
            || $request->boolean('verified')
            || $request->boolean('featured');

        $promotions = Product::query()
            ->promotion()
            ->active()
            ->with(['store.user'])
            ->withCount($this->promotionCountRelations())
            ->when($request->filled('type'), fn ($query) => $query->where('metadata->promotion_type', $request->string('type')->toString()))
            ->when($request->filled('platform'), fn ($query) => $query->where('metadata->platform', $request->string('platform')->toString()))
            ->when($request->filled('audience_niche'), fn ($query) => $query->whereJsonContains('metadata->audience_niches', $request->string('audience_niche')->toString()))
            ->when($request->filled('audience_region'), function ($query) use ($request) {
                $region = strtolower($request->string('audience_region')->toString());
                $query->whereRaw('LOWER(JSON_EXTRACT(metadata, "$.audience_regions")) like ?', ['%'.$region.'%']);
            })
            ->when($request->filled('content_format'), fn ($query) => $query->whereJsonContains('metadata->content_formats', $request->string('content_format')->toString()))
            ->when($request->filled('channel'), function ($query) use ($request) {
                $value = strtolower($request->string('channel')->toString());
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.channel"))) like ?', ['%'.$value.'%']);
            })
            ->when($request->filled('placement'), function ($query) use ($request) {
                $value = strtolower($request->string('placement')->toString());
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.placement"))) like ?', ['%'.$value.'%']);
            })
            ->when($request->filled('proof_type'), function ($query) use ($request) {
                $value = strtolower($request->string('proof_type')->toString());
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.proof"))) like ?', ['%'.$value.'%']);
            })
            ->when($request->filled('timing'), function ($query) use ($request) {
                $value = strtolower($request->string('timing')->toString());
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.timing"))) like ?', ['%'.$value.'%']);
            })
            ->when($request->filled('min_reach'), fn ($query) => $query->where('metadata->estimated_reach', '>=', (int) $request->integer('min_reach')))
            ->when($request->filled('max_reach'), fn ($query) => $query->where('metadata->estimated_reach', '<=', (int) $request->integer('max_reach')))
            ->when($request->filled('min_price_credits'), fn ($query) => $query->where('price_credits', '>=', (int) $request->integer('min_price_credits')))
            ->when($request->filled('max_price_credits'), fn ($query) => $query->where('price_credits', '<=', (int) $request->integer('max_price_credits')))
            ->when($request->filled('min_price_ugx'), fn ($query) => $query->where('price_ugx', '>=', (float) $request->input('min_price_ugx')))
            ->when($request->filled('max_price_ugx'), fn ($query) => $query->where('price_ugx', '<=', (float) $request->input('max_price_ugx')))
            ->when($request->filled('delivery_days_max'), fn ($query) => $query->where('metadata->delivery_days_max', '<=', (int) $request->integer('delivery_days_max')))
            ->when($request->filled('rating_min'), fn ($query) => $query->where('average_rating', '>=', (float) $request->input('rating_min')))
            ->when($request->boolean('featured'), fn ($query) => $query->where('is_featured', true))
            ->when($request->boolean('verified'), fn ($query) => $query->whereHas('store.user', fn ($userQuery) => $userQuery->where('is_verified', true)))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereRaw('LOWER(JSON_EXTRACT(metadata, "$.audience_regions")) like ?', ['%'.strtolower($search).'%'])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(metadata, "$.audience_niches")) like ?', ['%'.strtolower($search).'%'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.channel"))) like ?', ['%'.strtolower($search).'%'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.placement"))) like ?', ['%'.strtolower($search).'%'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.proof"))) like ?', ['%'.strtolower($search).'%'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.timing"))) like ?', ['%'.strtolower($search).'%'])
                        ->orWhereHas('store.user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%");
                        });
                });
            })
            ->when($sort === 'best_match' || ($sort === '' && $hasStructuredMatchFilters), function ($query) use ($request) {
                $weights = [];
                $bindings = [];

                if ($request->filled('type')) {
                    $weights[] = 'CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.promotion_type")) = ? THEN 25 ELSE 0 END';
                    $bindings[] = $request->string('type')->toString();
                }

                if ($request->filled('platform')) {
                    $weights[] = 'CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform")) = ? THEN 22 ELSE 0 END';
                    $bindings[] = $request->string('platform')->toString();
                }

                if ($request->filled('audience_niche')) {
                    $weights[] = 'CASE WHEN JSON_CONTAINS(JSON_EXTRACT(metadata, "$.audience_niches"), JSON_QUOTE(?)) THEN 18 ELSE 0 END';
                    $bindings[] = $request->string('audience_niche')->toString();
                }

                if ($request->filled('content_format')) {
                    $weights[] = 'CASE WHEN JSON_CONTAINS(JSON_EXTRACT(metadata, "$.content_formats"), JSON_QUOTE(?)) THEN 14 ELSE 0 END';
                    $bindings[] = $request->string('content_format')->toString();
                }

                foreach ([
                    'channel' => 12,
                    'placement' => 10,
                    'proof_type' => 10,
                    'timing' => 8,
                ] as $field => $weight) {
                    if ($request->filled($field)) {
                        $jsonField = $field === 'proof_type' ? 'proof' : $field;
                        $weights[] = sprintf(
                            'CASE WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.platform_specifics.%s"))) like ? THEN %d ELSE 0 END',
                            $jsonField,
                            $weight
                        );
                        $bindings[] = '%'.strtolower($request->string($field)->toString()).'%';
                    }
                }

                if ($request->filled('audience_region')) {
                    $weights[] = 'CASE WHEN LOWER(JSON_EXTRACT(metadata, "$.audience_regions")) like ? THEN 10 ELSE 0 END';
                    $bindings[] = '%'.strtolower($request->string('audience_region')->toString()).'%';
                }

                if ($request->filled('delivery_days_max')) {
                    $weights[] = 'CASE WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.delivery_days_max")) AS UNSIGNED) <= ? THEN 8 ELSE 0 END';
                    $bindings[] = (int) $request->integer('delivery_days_max');
                }

                if ($request->filled('rating_min')) {
                    $weights[] = 'CASE WHEN average_rating >= ? THEN 6 ELSE 0 END';
                    $bindings[] = (float) $request->input('rating_min');
                }

                if ($request->boolean('verified')) {
                    $weights[] = 'CASE WHEN EXISTS (SELECT 1 FROM users WHERE users.id = stores.user_id AND users.is_verified = 1) THEN 6 ELSE 0 END';
                }

                if ($request->boolean('featured')) {
                    $weights[] = 'CASE WHEN is_featured = 1 THEN 4 ELSE 0 END';
                }

                if ($weights === []) {
                    $query->orderByDesc('is_featured')->orderByDesc('total_orders')->orderByDesc('created_at');

                    return;
                }

                $query
                    ->leftJoin('stores', 'stores.id', '=', 'store_products.store_id')
                    ->select('store_products.*')
                    ->selectRaw('('.implode(' + ', $weights).') as match_score', $bindings)
                    ->orderByDesc('match_score')
                    ->orderByDesc('is_featured')
                    ->orderByDesc('average_rating')
                    ->orderByDesc('total_orders')
                    ->orderByDesc('created_at');
            })
            ->when($sort === 'price_asc', fn ($query) => $query->orderBy('price_ugx')->orderBy('price_credits'))
            ->when($sort === 'price_desc', fn ($query) => $query->orderByDesc('price_ugx')->orderByDesc('price_credits'))
            ->when($sort === 'rating', fn ($query) => $query->orderByDesc('average_rating')->orderByDesc('rating_count'))
            ->when($sort === 'newest', fn ($query) => $query->orderByDesc('created_at'))
            ->when(in_array($sort, ['', 'popularity'], true) && ! $hasStructuredMatchFilters, fn ($query) => $query->orderByDesc('is_featured')->orderByDesc('total_orders')->orderByDesc('created_at'))
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => collect($promotions->items())->map(fn (Product $promotion) => $this->serializePromotion($promotion))->values(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'total' => $promotions->total(),
                'per_page' => $promotions->perPage(),
                'last_page' => $promotions->lastPage(),
            ],
        ]);
    }

    public function myPromotions(Request $request): JsonResponse
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

        $promotions = Product::query()
            ->promotion()
            ->where('store_id', $storeId)
            ->with(['store.user'])
            ->withCount($this->promotionCountRelations())
            ->latest()
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => collect($promotions->items())->map(fn (Product $promotion) => $this->serializePromotion($promotion))->values(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'total' => $promotions->total(),
                'per_page' => $promotions->perPage(),
                'last_page' => $promotions->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $promotion = Product::query()
            ->promotion()
            ->active()
            ->with($this->promotionDetailRelations())
            ->withCount($this->promotionCountRelations())
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializePromotion($promotion, true),
        ]);
    }

    public function platforms(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['slug' => 'instagram', 'name' => 'Instagram', 'icon_url' => null],
                ['slug' => 'tiktok', 'name' => 'TikTok', 'icon_url' => null],
                ['slug' => 'facebook', 'name' => 'Facebook', 'icon_url' => null],
                ['slug' => 'youtube', 'name' => 'YouTube', 'icon_url' => null],
                ['slug' => 'twitter', 'name' => 'Twitter / X', 'icon_url' => null],
                ['slug' => 'spotify', 'name' => 'Spotify', 'icon_url' => null],
                ['slug' => 'apple_music', 'name' => 'Apple Music', 'icon_url' => null],
                ['slug' => 'radio', 'name' => 'Radio', 'icon_url' => null],
                ['slug' => 'club', 'name' => 'Club / Venue', 'icon_url' => null],
                ['slug' => 'event', 'name' => 'Event', 'icon_url' => null],
                ['slug' => 'blog', 'name' => 'Blog', 'icon_url' => null],
                ['slug' => 'podcast', 'name' => 'Podcast', 'icon_url' => null],
                ['slug' => 'other', 'name' => 'Other', 'icon_url' => null],
            ],
        ]);
    }

    public function promoterProfile(string $username): JsonResponse
    {
        $user = User::query()
            ->where('username', $username)
            ->firstOrFail();

        $store = $user->store;
        $promotions = Product::query()
            ->promotion()
            ->active()
            ->where('store_id', $store?->id)
            ->with(['store.user'])
            ->withCount($this->promotionCountRelations())
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->get();

        $profile = (array) data_get($store?->metadata ?? [], 'promoter_profile', []);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url ?? $user->avatar ?? null,
                'banner_url' => $store?->banner ?? $user->banner ?? null,
                'bio' => $store?->description ?? $user->bio ?? null,
                'location' => $this->formatLocation($store?->city ?? $user->city, $store?->country ?? $user->country, $profile['location'] ?? null),
                'is_verified' => (bool) ($user->is_verified ?? $store?->is_verified ?? false),
                'follower_count' => (int) ($user->followers_count ?? 0),
                'total_promotions' => $promotions->count(),
                'active_promotions' => $promotions->count(),
                'featured_promotions' => $promotions->where('is_featured', true)->count(),
                'average_rating' => round((float) ($promotions->avg('average_rating') ?? 0), 2),
                'completed_orders' => (int) $promotions->sum('completed_orders'),
                'platforms' => $promotions->pluck('metadata.platform')->filter()->unique()->values()->all(),
                'service_types' => $promotions->pluck('metadata.promotion_type')->filter()->unique()->values()->all(),
                'social_links' => $this->serializePromoterSocialLinks($user, $profile),
                'audience_summary' => $profile['audience_summary'] ?? null,
                'response_time_hours' => isset($profile['response_time_hours']) ? (int) $profile['response_time_hours'] : null,
                'proof_points' => array_values(array_filter((array) ($profile['proof_points'] ?? []))),
                'campaign_highlights' => array_values(array_filter((array) ($profile['campaign_highlights'] ?? []))),
                'portfolio_items' => $this->serializePortfolioItems($profile['portfolio_items'] ?? []),
                'promotions' => $promotions->map(fn (Product $promotion) => $this->serializePromotion($promotion))->values()->all(),
            ],
        ]);
    }

    public function purchase(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:credits,ugx,hybrid',
            'song_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            'preferred_delivery_date' => 'nullable|date',
        ]);

        $user = $request->user();
        $promotion = Product::query()
            ->promotion()
            ->active()
            ->with('store.user')
            ->where('slug', $slug)
            ->firstOrFail();

        $paymentService = app(PaymentService::class);
        $settlementService = app(PromotionSettlementService::class);
        $creditsBalance = (float) ($user?->creditWallet?->available_credits ?? $user?->credits ?? 0);
        $walletBalance = (float) ($user?->ugx_balance ?? 0);
        $priceCredits = (int) ($promotion->price_credits ?? 0);
        $priceUgx = (float) ($promotion->price_ugx ?? 0);
        $paymentMethod = $validated['payment_method'];
        $paymentProvider = $paymentMethod === 'credits' ? 'credits' : 'wallet';
        $paidCredits = 0;
        $paidUgx = 0.0;
        $settlementBreakdown = [];

        if ($paymentMethod === 'credits' && $creditsBalance < $priceCredits) {
            return response()->json(['message' => 'Insufficient credits.'], 422);
        }

        if ($paymentMethod === 'ugx' && $walletBalance < $priceUgx) {
            return response()->json(['message' => 'Insufficient wallet balance.'], 422);
        }

        if ($paymentMethod === 'hybrid') {
            $hybrid = $paymentService->calculateHybridPayment($priceUgx, $priceCredits, (int) round($creditsBalance), true);
            $paidCredits = (int) ($hybrid['credits_used'] ?? 0);
            $paidUgx = (float) ($hybrid['ugx_amount'] ?? 0);

            if ($creditsBalance < $paidCredits) {
                return response()->json(['message' => 'Insufficient credits for hybrid payment.'], 422);
            }

            if ($walletBalance < $paidUgx) {
                return response()->json(['message' => 'Insufficient wallet balance for hybrid payment.'], 422);
            }
        } elseif ($paymentMethod === 'credits') {
            $paidCredits = $priceCredits;
            $paidUgx = 0.0;
        } else {
            $paidUgx = $priceUgx;
        }

        $order = null;
        $item = null;
        $payment = null;

        DB::transaction(function () use (
            $request,
            $user,
            $promotion,
            $validated,
            $paymentMethod,
            $paymentProvider,
            $paidCredits,
            $paidUgx,
            $priceCredits,
            $priceUgx,
            $settlementService,
            &$order,
            &$item,
            &$payment,
            &$settlementBreakdown
        ) {
            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'store_id' => $promotion->store_id,
                'user_id' => $user->id,
                'status' => Order::STATUS_PROCESSING,
                'payment_status' => Order::PAYMENT_PAID,
                'payment_method' => $paymentMethod,
                'payment_provider' => $paymentProvider,
                'subtotal' => $priceUgx,
                'tax_amount' => 0,
                'shipping_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $priceUgx,
                'credit_amount' => $priceCredits,
                'subtotal_ugx' => $priceUgx,
                'subtotal_credits' => $priceCredits,
                'tax_ugx' => 0,
                'tax_credits' => 0,
                'shipping_cost_ugx' => 0,
                'shipping_cost_credits' => 0,
                'discount_ugx' => 0,
                'discount_credits' => 0,
                'platform_fee_ugx' => 0,
                'platform_fee_credits' => 0,
                'total_ugx' => $priceUgx,
                'total_credits' => $priceCredits,
                'paid_ugx' => $paidUgx,
                'paid_credits' => $paidCredits,
                'customer_notes' => $validated['notes'] ?? null,
                'paid_at' => now(),
            ]);

            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $promotion->id,
                'product_snapshot' => [
                    'promotion' => $this->serializePromotion($promotion, true),
                ],
                'product_name' => $promotion->name,
                'product_description' => $promotion->description,
                'product_image' => $promotion->featured_image,
                'product_type' => $promotion->product_type,
                'quantity' => 1,
                'unit_price' => $priceUgx,
                'price_ugx' => $priceUgx,
                'price_credits' => $priceCredits,
                'payment_method' => $paymentMethod,
                'subtotal' => $priceUgx,
                'tax_amount' => 0,
                'total_amount' => $priceUgx,
                'fulfillment_status' => OrderItem::STATUS_PENDING,
                'verification_status' => 'pending',
                'verification_notes' => $validated['notes'] ?? null,
            ]);

            $settlementBreakdown = $settlementService->buildBreakdown($order, $promotion, $promotion->store?->user);
            $item->forceFill([
                'product_snapshot' => array_merge($item->product_snapshot ?? [], [
                    'promotion_settlement' => $settlementBreakdown,
                ]),
            ])->save();

            if ($paymentMethod === 'credits') {
                $user->spendCredits(
                    $paidCredits,
                    'promotion_purchase',
                    "Promotion purchase {$order->order_number}",
                    ['order_id' => $order->id, 'promotion_id' => $promotion->id]
                );
            } elseif ($paymentMethod === 'ugx') {
                $user->decrement('ugx_balance', $paidUgx);
            } else {
                if ($paidCredits > 0) {
                    $user->spendCredits(
                        $paidCredits,
                        'promotion_purchase',
                        "Promotion hybrid purchase {$order->order_number}",
                        ['order_id' => $order->id, 'promotion_id' => $promotion->id]
                    );
                }

                if ($paidUgx > 0) {
                    $user->decrement('ugx_balance', $paidUgx);
                }
            }

            $payment = Payment::create([
                'user_id' => $user->id,
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'payment_type' => 'promotion_purchase',
                'payment_method' => $paymentMethod,
                'provider' => $paymentProvider,
                'payment_provider' => $paymentProvider,
                'currency' => 'UGX',
                'description' => "Promotion purchase for {$promotion->name}",
                'metadata' => [
                    'order_id' => $order->id,
                    'promotion_id' => $promotion->id,
                    'credits_used' => $paidCredits,
                    'ugx_paid' => $paidUgx,
                    'promotion_settlement' => $settlementBreakdown,
                ],
            ]);

            $payment->forceFill([
                'amount' => $paidUgx > 0 ? $paidUgx : $priceUgx,
                'status' => Payment::STATUS_COMPLETED,
                'payment_reference' => 'PAY-'.strtoupper(Str::random(12)),
                'transaction_id' => 'TRX-'.strtoupper(Str::random(12)),
                'completed_at' => now(),
            ])->save();

            $order->forceFill([
                'payment_reference' => $payment->payment_reference,
                'transaction_id' => $payment->transaction_id,
            ])->save();
        });

        $this->logPromotionActivity($user, 'promotion_purchase_created', $order, [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'promotion_id' => $promotion->id,
            'promotion_slug' => $promotion->slug,
            'seller_user_id' => $promotion->store?->user_id,
            'payment_id' => $payment?->id,
            'payment_method' => $paymentMethod,
            'credits_used' => $paidCredits,
            'ugx_paid' => $paidUgx,
            'settlement' => $settlementService->summarize($item),
        ]);

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => 'pending_verification',
            'payment_status' => $order->payment_status,
            'total_credits' => $priceCredits,
            'total_ugx' => $priceUgx,
            'created_at' => optional($order->created_at)->toIso8601String(),
        ], 201);
    }

    public function myPurchases(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()?->id)
            ->with(['items.product.store.user', 'buyer'])
            ->latest()
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order) => $this->serializeOrder($order))->values(),
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
        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', $request->user()?->id)
            ->with(['items.product.store.user', 'buyer'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializeOrder($order),
        ]);
    }

    public function sellerOrders(Request $request): JsonResponse
    {
        $storeId = $request->user()?->store?->id;

        $orders = Order::query()
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product.store.user', 'buyer'])
            ->latest()
            ->paginate($this->getPerPage($request));

        return response()->json([
            'data' => collect($orders->items())->map(fn (Order $order) => $this->serializeOrder($order))->values(),
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

    public function submitVerification(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'verification_url' => 'required|string|max:2048',
            'verification_notes' => 'nullable|string|max:2000',
            'verification_files' => 'nullable|array',
            'verification_files.*' => 'nullable|string|max:2048',
        ]);

        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', $request->user()?->id)
            ->with('items')
            ->firstOrFail();

        $orderItem = $order->items->first();
        if (! $orderItem) {
            return response()->json(['message' => 'Promotion order item not found.'], 404);
        }

        if ($order->payment_status === Order::PAYMENT_REFUNDED || $order->status === Order::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'This promotion order has already been refunded.',
            ], 422);
        }

        if ($orderItem->verification_status === 'verified') {
            return response()->json([
                'message' => 'Verification has already been approved for this order.',
            ], 422);
        }

        $orderItem->forceFill([
            'verification_status' => 'submitted',
            'verification_url' => $validated['verification_url'],
            'verification_notes' => $validated['verification_notes'] ?? null,
            'verification_proof' => $validated['verification_files'] ?? null,
            'verification_submitted_at' => now(),
        ])->save();

        $this->logPromotionActivity($request->user(), 'promotion_verification_submitted', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'verification_url' => $orderItem->verification_url,
            'verification_files_count' => count($validated['verification_files'] ?? []),
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
            'reason_code' => 'nullable|in:missing_delivery,wrong_platform,poor_quality_proof,late_delivery,scope_mismatch,fraud_or_spam,other',
            'evidence_url' => 'nullable|string|max:2048',
            'evidence_files' => 'nullable|array',
            'evidence_files.*' => 'nullable|string|max:2048',
        ]);

        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', $request->user()?->id)
            ->with('items')
            ->firstOrFail();

        $orderItem = $order->items->first();
        if (! $orderItem) {
            return response()->json(['message' => 'Promotion order item not found.'], 404);
        }

        $snapshot = is_array($orderItem->product_snapshot ?? null) ? $orderItem->product_snapshot : [];
        $disputeMeta = (array) data_get($snapshot, 'promotion_dispute', []);

        if (($disputeMeta['state'] ?? null) === 'open') {
            return response()->json([
                'message' => 'A dispute is already open for this promotion order.',
            ], 422);
        }

        if ($order->payment_status === Order::PAYMENT_REFUNDED || $order->status === Order::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'This promotion order has already been refunded.',
            ], 422);
        }

        $snapshot['promotion_dispute'] = [
            'reason' => $validated['reason'],
            'reason_code' => $validated['reason_code'] ?? null,
            'state' => 'open',
            'created_at' => now()->toIso8601String(),
            'created_by' => $request->user()?->id,
            'evidence_url' => $validated['evidence_url'] ?? null,
            'evidence_files' => array_values(array_filter((array) ($validated['evidence_files'] ?? []))),
            'resolution' => null,
            'admin_notes' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'settlement' => app(PromotionSettlementService::class)->summarize($orderItem),
        ];

        $orderItem->forceFill([
            'dispute_reason' => $validated['reason'],
            'product_snapshot' => $snapshot,
        ])->save();

        $this->logPromotionActivity($request->user(), 'promotion_dispute_created', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'reason' => $validated['reason'],
            'reason_code' => $validated['reason_code'] ?? null,
            'verification_status' => $orderItem->verification_status,
            'evidence_url' => $validated['evidence_url'] ?? null,
            'evidence_files_count' => count($validated['evidence_files'] ?? []),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dispute submitted successfully.',
        ]);
    }

    public function verifyCompletion(Request $request, int $orderId): JsonResponse
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->whereHas('items.product.store', fn ($query) => $query->where('user_id', $request->user()?->id))
            ->with(['items.product.store.user', 'buyer'])
            ->firstOrFail();

        $orderItem = $order->items->first();
        if (! $orderItem) {
            return response()->json(['message' => 'Promotion order item not found.'], 404);
        }

        if (! in_array($orderItem->verification_status, ['submitted'], true)) {
            return response()->json([
                'message' => 'Seller payout can only be released after verification proof is submitted.',
            ], 422);
        }

        $snapshot = is_array($orderItem->product_snapshot ?? null) ? $orderItem->product_snapshot : [];
        $disputeMeta = (array) data_get($snapshot, 'promotion_dispute', []);
        if (($disputeMeta['state'] ?? null) === 'open' || (! empty($orderItem->dispute_reason) && empty($disputeMeta['resolved_at']))) {
            return response()->json([
                'message' => 'This order has an open dispute and must be resolved before payout is released.',
            ], 422);
        }

        $settlement = app(PromotionSettlementService::class)->settleOrder($order, $orderItem);

        $orderItem->forceFill([
            'verification_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $request->user()?->id,
            'rejection_reason' => null,
        ])->save();

        $order->forceFill([
            'status' => Order::STATUS_COMPLETED,
            'completed_at' => now(),
            'payment_status' => Order::PAYMENT_PAID,
        ])->save();

        $this->logPromotionActivity($request->user(), 'promotion_payout_released', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'buyer_user_id' => $order->user_id,
            'verified_by' => $request->user()?->id,
            'settlement' => $settlement,
        ]);

        return response()->json([
            'success' => true,
            'payment_released' => true,
        ]);
    }

    public function rejectCompletion(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $order = Order::query()
            ->where('id', $orderId)
            ->whereHas('items.product.store', fn ($query) => $query->where('user_id', $request->user()?->id))
            ->with(['items.product.store.user', 'buyer'])
            ->firstOrFail();

        $orderItem = $order->items->first();
        if (! $orderItem) {
            return response()->json(['message' => 'Promotion order item not found.'], 404);
        }

        if ($order->payment_status === Order::PAYMENT_REFUNDED || $order->status === Order::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'This promotion order has already been refunded.',
            ], 422);
        }

        $settlement = app(PromotionSettlementService::class)->reverseOrder($order, $orderItem, $validated['reason']);

        $orderItem->forceFill([
            'verification_status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'verified_at' => null,
            'verified_by' => $request->user()?->id,
        ])->save();

        $order->forceFill([
            'status' => Order::STATUS_CANCELLED,
            'payment_status' => Order::PAYMENT_REFUNDED,
            'refunded_at' => now(),
            'refund_reason' => $validated['reason'],
        ])->save();

        $this->logPromotionActivity($request->user(), 'promotion_payout_reversed', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'buyer_user_id' => $order->user_id,
            'rejected_by' => $request->user()?->id,
            'reason' => $validated['reason'],
            'settlement' => $settlement,
        ]);

        return response()->json([
            'success' => true,
            'refund_issued' => true,
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $storeId = $request->user()?->store?->id;

        $promotions = Product::query()
            ->promotion()
            ->where('store_id', $storeId)
            ->withCount($this->promotionCountRelations())
            ->get();

        $orders = Order::query()
            ->whereHas('items.product', fn ($query) => $query->promotion()->where('store_id', $storeId))
            ->with(['items.product', 'buyer'])
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

    public function review(Request $request, int $orderId): JsonResponse
    {
        if (! $this->reviewsTableAvailable()) {
            return response()->json([
                'message' => 'Promotion reviews are not available in this environment yet.',
            ], 503);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:2000',
            'would_recommend' => 'sometimes|boolean',
        ]);

        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', $request->user()?->id)
            ->with(['items.product'])
            ->firstOrFail();

        $orderItem = $order->items->first();
        if (! $orderItem) {
            return response()->json(['message' => 'Promotion order item not found.'], 404);
        }

        $review = Review::updateOrCreate(
            [
                'user_id' => $request->user()?->id,
                'reviewable_type' => Product::class,
                'reviewable_id' => $orderItem->product_id,
            ],
            [
                'rating' => $validated['rating'],
                'content' => $validated['comment'],
                'status' => Review::STATUS_APPROVED,
                'is_verified_purchase' => true,
                'metadata' => [
                    'order_id' => $orderItem->order_id,
                    'source' => 'promotion_order',
                    'would_recommend' => (bool) ($validated['would_recommend'] ?? ($validated['rating'] >= 4)),
                ],
            ]
        );

        $this->logPromotionActivity($request->user(), 'promotion_review_submitted', $orderItem, [
            'order_id' => $order->id,
            'promotion_id' => $orderItem->product_id,
            'review_id' => $review->id,
            'rating' => $review->rating,
            'would_recommend' => (bool) ($validated['would_recommend'] ?? ($review->rating >= 4)),
        ]);

        return response()->json([
            'success' => true,
            'review_id' => $review->id,
        ]);
    }

    protected function getPerPage(Request $request, int $default = 20, int $max = 100): int
    {
        return parent::getPerPage($request, $default, $max);
    }

    private function serializePromotion(Product $promotion, bool $includeReviews = false): array
    {
        $promotion->loadMissing(['store.user']);
        $metadata = is_array($promotion->metadata ?? null) ? $promotion->metadata : [];

        return [
            'id' => $promotion->id,
            'slug' => $promotion->slug,
            'title' => $promotion->name,
            'short_description' => $promotion->short_description ?: Str::limit((string) $promotion->description, 120),
            'description' => $promotion->description,
            'type' => (string) data_get($metadata, 'promotion_type', 'social_media_mention'),
            'platform' => (string) data_get($metadata, 'platform', 'other'),
            'price_credits' => (int) ($promotion->price_credits ?? 0),
            'price_ugx' => (float) ($promotion->price_ugx ?? 0),
            'accepts_credits' => (bool) ($promotion->allow_credit_payment || $promotion->accepts_credits),
            'accepts_ugx' => (float) ($promotion->price_ugx ?? 0) > 0,
            'accepts_hybrid' => (bool) ($promotion->allow_hybrid_payment),
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
            'promoter' => $promotion->store?->user ? $this->serializeUserSummary($promotion->store->user) : null,
            'featured_image_url' => $promotion->featured_image_url,
            'status' => $promotion->status,
            'created_at' => optional($promotion->created_at)->toIso8601String(),
            'reviews' => $includeReviews && $this->reviewsTableAvailable() ? collect($promotion->approvedGenericReviews ?? [])->map(fn (Review $review) => [
                'id' => $review->id,
                'promotion_id' => $promotion->id,
                'order_id' => data_get($review->metadata ?? [], 'order_id'),
                'rating' => (int) $review->rating,
                'comment' => $review->content,
                'would_recommend' => (bool) data_get($review->metadata ?? [], 'would_recommend', $review->rating >= 4),
                'helpful_count' => (int) ($review->helpful_count ?? 0),
                'reviewer' => $review->user ? $this->serializeUserSummary($review->user) : null,
                'created_at' => optional($review->created_at)->toIso8601String(),
            ])->values()->all() : [],
        ];
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

    private function promotionDetailRelations(): array
    {
        $relations = ['store.user'];

        if ($this->reviewsTableAvailable()) {
            $relations[] = 'approvedGenericReviews.user';
        }

        return $relations;
    }

    private function reviewsTableAvailable(): bool
    {
        static $available;

        if ($available === null) {
            $available = Schema::hasTable('reviews');
        }

        return $available;
    }

    private function serializeOrder(Order $order): array
    {
        $item = $order->items->first();
        $snapshot = is_array($item?->product_snapshot ?? null) ? $item->product_snapshot : [];
        $disputeMeta = (array) data_get($snapshot, 'promotion_dispute', []);
        $settlement = $item ? app(PromotionSettlementService::class)->summarize($item) : ['status' => 'pending'];
        $disputeResolution = data_get($disputeMeta, 'resolution');
        $disputeResolvedAt = data_get($disputeMeta, 'resolved_at');
        $verificationStatus = match ($item?->verification_status) {
            'submitted' => 'verification_submitted',
            'verified' => 'completed',
            'rejected' => 'disputed',
            default => 'pending_verification',
        };

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $verificationStatus,
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
                'state' => ! empty($item?->dispute_reason)
                    ? ($disputeResolvedAt || $disputeResolution ? 'resolved' : 'open')
                    : null,
                'reason_code' => data_get($disputeMeta, 'reason_code'),
                'dispute_reason' => $item?->dispute_reason ?? null,
                'reason' => $item?->dispute_reason ?? null,
                'disputed_at' => data_get($disputeMeta, 'created_at') ?? optional($item?->updated_at)->toIso8601String(),
                'created_at' => optional($order->created_at)->toIso8601String(),
                'resolved_at' => $disputeResolvedAt ?? optional($order->refunded_at)->toIso8601String(),
                'resolution' => $disputeResolution,
                'resolution_notes' => data_get($disputeMeta, 'admin_notes') ?? $order->refund_reason ?? $item?->rejection_reason,
                'admin_notes' => data_get($disputeMeta, 'admin_notes') ?? null,
                'evidence_url' => data_get($disputeMeta, 'evidence_url') ?? $item?->verification_url ?? null,
                'evidence_files' => array_values(array_filter((array) data_get($disputeMeta, 'evidence_files', []))),
                'settlement_status' => data_get($disputeMeta, 'settlement.status') ?? data_get($settlement, 'status'),
                'refund_reason' => $order->refund_reason ?? null,
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

    private function serializePromoterSocialLinks(User $user, array $profile = []): array
    {
        return [
            'instagram_url' => $user->instagram_url ?? null,
            'twitter_url' => $user->twitter_url ?? null,
            'facebook_url' => $user->facebook_url ?? null,
            'youtube_url' => $user->youtube_url ?? null,
            'tiktok_url' => $user->tiktok_url ?? null,
            'website_url' => $profile['website_url'] ?? null,
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

    private function logPromotionActivity(?User $actor, string $action, $auditable, array $data = []): void
    {
        if (! $actor || ! $auditable || ! isset($auditable->id)) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => $action,
                'auditable_type' => get_class($auditable),
                'auditable_id' => $auditable->id,
                'old_values' => null,
                'new_values' => $data,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'url' => request()->fullUrl(),
            ]);
        } catch (Throwable) {
            // Audit logging is best-effort and must not break checkout or settlement flows.
        }
    }
}
