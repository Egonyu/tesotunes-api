<?php

namespace App\Modules\Promotions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Models\PromotionApplication;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Modules\Store\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityHubController extends Controller
{
    /**
     * Universal summary card — wallet, credits, promoter status, pending actions.
     * Replaces fragmented /artist/promotions and /promotions/purchases views.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->promoterProfile;

        $pendingBuyerOrders = Order::where('buyer_id', $user->id)
            ->whereIn('payment_status', ['paid', 'partially_refunded'])
            ->where(function ($q) {
                $q->whereHas('items', fn ($i) => $i->where('verification_status', 'pending_verification'));
            })
            ->count();

        $pendingSellerOrders = 0;
        if ($profile?->store_id) {
            $pendingSellerOrders = Order::where('store_id', $profile->store_id)
                ->whereIn('payment_status', ['paid'])
                ->whereHas('items', fn ($i) => $i->where('verification_status', 'submitted'))
                ->count();
        }

        $openOpportunities = PromotionOpportunity::where('created_by_user_id', $user->id)
            ->where('status', PromotionOpportunity::STATUS_OPEN)
            ->count();

        $pendingApplications = 0;
        if ($profile) {
            $pendingApplications = PromotionApplication::where('promoter_profile_id', $profile->id)
                ->where('status', PromotionApplication::STATUS_SUBMITTED)
                ->count();
        }

        return response()->json([
            'data' => [
                'wallet' => [
                    'ugx_balance' => (float) ($user->ugx_balance ?? 0),
                    'credits' => (int) ($user->credits ?? 0),
                ],
                'promoter' => $profile ? [
                    'is_promoter' => true,
                    'display_name' => $profile->display_name,
                    'slug' => $profile->slug,
                    'tier' => $profile->tier,
                    'is_verified' => $profile->is_verified,
                    'average_rating' => $profile->average_rating,
                    'total_completed_orders' => $profile->total_completed_orders,
                ] : ['is_promoter' => false],
                'pending_actions' => [
                    'buyer_orders_awaiting_review' => $pendingBuyerOrders,
                    'seller_orders_to_verify' => $pendingSellerOrders,
                    'open_opportunities' => $openOpportunities,
                    'pending_applications' => $pendingApplications,
                ],
            ],
        ]);
    }

    /**
     * Wallet detail — credits history + UGX balance.
     */
    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'ugx_balance' => (float) ($user->ugx_balance ?? 0),
                'credits' => (int) ($user->credits ?? 0),
            ],
        ]);
    }

    /**
     * All orders (as buyer) with promotion context.
     */
    public function orders(Request $request): JsonResponse
    {
        $orders = Order::with(['items.product', 'store'])
            ->where('buyer_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($orders);
    }

    /**
     * Opportunities the authenticated user has posted.
     */
    public function opportunities(Request $request): JsonResponse
    {
        $opportunities = PromotionOpportunity::with('promotable')
            ->where('created_by_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($opportunities);
    }

    /**
     * Applications the authenticated promoter has submitted.
     */
    public function applications(Request $request): JsonResponse
    {
        $profile = $request->user()->promoterProfile;

        if (! $profile) {
            return response()->json(['data' => []]);
        }

        $applications = PromotionApplication::with('opportunity.promotable')
            ->where('promoter_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($applications);
    }

    /**
     * Earnings summary for promoters (seller view).
     */
    public function earnings(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->promoterProfile;

        if (! $profile?->store_id) {
            return response()->json(['data' => ['total_ugx' => 0, 'total_credits' => 0, 'orders' => []]]);
        }

        $orders = Order::with(['items'])
            ->where('store_id', $profile->store_id)
            ->where('payment_status', 'paid')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        $totals = Order::where('store_id', $profile->store_id)
            ->where('payment_status', 'paid')
            ->selectRaw('SUM(paid_ugx) as total_ugx, SUM(paid_credits) as total_credits')
            ->first();

        return response()->json([
            'data' => [
                'total_ugx' => (float) ($totals->total_ugx ?? 0),
                'total_credits' => (int) ($totals->total_credits ?? 0),
                'orders' => $orders,
            ],
        ]);
    }
}
