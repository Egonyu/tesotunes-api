<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * GET /api/subscription-plans — list available plans (public)
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'description' => $plan->description,
                'tier' => $plan->tier,
                'type' => $plan->type,
                'price' => $plan->price,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'price_local' => $plan->price_local,
                'currency' => $plan->currency,
                'trial_days' => $plan->trial_days,
                'features' => $plan->features ?? [],
                'limits' => [
                    'downloads_per_day' => $plan->max_downloads_per_day ?? $plan->downloads_per_day,
                    'uploads_per_month' => $plan->max_uploads_per_month,
                    'audio_quality_kbps' => $plan->max_audio_quality_kbps,
                ],
                'has_ads' => (bool) $plan->has_ads,
                'offline_mode' => (bool) $plan->offline_mode,
                'is_featured' => (bool) $plan->is_featured,
                'is_popular' => (bool) $plan->is_popular,
            ]);

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * GET /api/user/subscription — current user's subscription
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = $user->subscription;

        $downloadsToday = Download::where('user_id', $user->id)
            ->whereDate('downloaded_at', today())
            ->count();

        $uploadsThisMonth = $user->artist
            ? $user->artist->songs()
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count()
            : 0;

        if (! $sub || ! $sub->isActive()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_subscription' => false,
                    'plan' => 'free',
                    'limits' => [
                        'downloads_per_day' => 3,
                        'downloads_today' => $downloadsToday,
                        'audio_quality_kbps' => 128,
                        'uploads_per_month' => 0,
                        'uploads_this_month' => $uploadsThisMonth,
                    ],
                ],
            ]);
        }

        $plan = $sub->subscriptionPlan;
        $downloadLimit = $this->resolveCurrentDownloadsLimit($plan);
        $audioQuality = $this->resolveAudioQualityLimit($plan);
        $uploadLimit = $this->resolveUploadsLimit($plan);
        $adFree = $this->resolveAdFree($plan);
        $offlineAccess = $this->resolveOfflineAccess($plan);

        return response()->json([
            'success' => true,
            'data' => [
                'has_subscription' => true,
                'subscription_id' => $sub->id,
                'plan' => $plan?->slug ?? 'unknown',
                'plan_name' => $plan?->name,
                'tier' => $plan?->tier,
                'status' => $sub->status,
                'started_at' => $sub->started_at?->toIso8601String(),
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'days_remaining' => $sub->daysUntilExpiry(),
                'auto_renew' => (bool) $sub->auto_renew,
                'ad_free' => $adFree,
                'offline_access' => $offlineAccess,
                'limits' => [
                    'downloads_per_day' => $downloadLimit,
                    'downloads_today' => $downloadsToday,
                    'audio_quality_kbps' => $audioQuality,
                    'uploads_per_month' => $uploadLimit,
                    'uploads_this_month' => $uploadsThisMonth,
                ],
            ],
        ]);
    }

    /**
     * POST /api/subscriptions/subscribe — subscribe to a plan
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:mobile_money,card',
            'phone_number' => 'required|string|min:10|max:15',
            'billing_period' => 'nullable|in:monthly,yearly',
        ]);

        $user = $request->user();
        $plan = SubscriptionPlan::where('id', $validated['plan_id'])
            ->where('is_active', true)
            ->firstOrFail();

        // Free plan — no payment needed
        if ($plan->price <= 0 && $plan->price_local <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Free plan does not require subscription. All users start on the free tier.',
            ], 422);
        }

        // Prevent re-subscribing to the same plan
        $existing = UserSubscription::where('user_id', $user->id)
            ->where('subscription_plan_id', $plan->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active subscription to this plan.',
                'data' => [
                    'expires_at' => $existing->expires_at->toIso8601String(),
                    'days_remaining' => $existing->daysUntilExpiry(),
                ],
            ], 422);
        }

        try {
            $result = $this->paymentService->processSubscriptionPayment(
                $user,
                $plan,
                $validated['payment_method'],
                [
                    'phone_number' => $validated['phone_number'],
                    'billing_period' => $validated['billing_period'] ?? 'monthly',
                ]
            );

            if ($result['success']) {
                $user->notify(new SubscriptionNotification(
                    SubscriptionNotification::SUBSCRIBED,
                    $plan->name,
                    ['expires_at' => $result['subscription_ends_at'] ?? 'N/A']
                ));
            }

            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription failed. Please try again.',
            ], 500);
        }
    }

    /**
     * POST /api/subscriptions/toggle-auto-renew — toggle auto renewal
     */
    public function toggleAutoRenew(Request $request): JsonResponse
    {
        $user = $request->user();
        $sub = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $sub) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found.',
            ], 404);
        }

        $sub->update(['auto_renew' => ! $sub->auto_renew]);

        return response()->json([
            'success' => true,
            'data' => ['auto_renew' => (bool) $sub->auto_renew],
            'message' => $sub->auto_renew
                ? 'Auto-renewal enabled. Your subscription will renew automatically.'
                : 'Auto-renewal disabled. Your subscription will expire on '.$sub->expires_at?->format('M d, Y').'.',
        ]);
    }

    /**
     * POST /api/subscriptions/{subscription}/cancel — cancel subscription
     */
    public function cancel(Request $request, $subscription): JsonResponse
    {
        $subscription = UserSubscription::where('id', $subscription)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (in_array($subscription->status, ['cancelled', 'expired'])) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is already '.$subscription->status.'.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->paymentService->cancelSubscription(
            $subscription,
            $validated['reason'] ?? ''
        );

        $request->user()->notify(new SubscriptionNotification(
            SubscriptionNotification::CANCELLED,
            $subscription->subscriptionPlan->name ?? 'Premium',
            ['expires_at' => $subscription->expires_at?->format('M d, Y') ?? 'N/A']
        ));

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Subscription cancelled successfully.',
        ]);
    }

    /**
     * POST /api/subscriptions/{subscription}/extend — extend subscription (admin)
     */
    public function extend(Request $request, $subscription): JsonResponse
    {
        $subscription = UserSubscription::findOrFail($subscription);

        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:365',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->paymentService->extendSubscription(
            $subscription,
            $validated['days'],
            $validated['reason'] ?? ''
        );

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Subscription extended successfully.',
        ]);
    }

    /**
     * POST /api/subscriptions/change-plan — upgrade or downgrade plan
     */
    public function changePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:mobile_money,card',
            'phone_number' => 'required|string|min:10|max:15',
        ]);

        $user = $request->user();
        $newPlan = SubscriptionPlan::where('id', $validated['plan_id'])
            ->where('is_active', true)
            ->firstOrFail();

        if ($newPlan->price <= 0 && $newPlan->price_local <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot switch to the free tier. Cancel your subscription instead.',
            ], 422);
        }

        $currentSub = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if (! $currentSub) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription to change. Use the subscribe endpoint instead.',
            ], 422);
        }

        if ($currentSub->subscription_plan_id === $newPlan->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are already on this plan.',
            ], 422);
        }

        $currentPlan = $currentSub->subscriptionPlan;
        $direction = ($newPlan->price > ($currentPlan->price ?? 0)) ? 'upgrade' : 'downgrade';

        // Calculate pro-rata credit for remaining days
        $daysRemaining = max(0, now()->diffInDays($currentSub->expires_at, false));
        $dailyRate = ($currentPlan->price_local ?: $currentPlan->price_monthly ?: $currentPlan->price) / 30;
        $credit = round($dailyRate * $daysRemaining, 2);
        $newAmount = $newPlan->price_local ?: $newPlan->price_monthly ?: $newPlan->price;
        $chargeAmount = max(0, $newAmount - $credit);

        try {
            if ($chargeAmount > 0) {
                $result = $this->paymentService->processSubscriptionPayment(
                    $user,
                    $newPlan,
                    $validated['payment_method'],
                    [
                        'phone_number' => $validated['phone_number'],
                        'billing_period' => 'monthly',
                        'change_type' => $direction,
                        'pro_rata_credit' => $credit,
                    ]
                );

                if (! $result['success']) {
                    return response()->json($result, 422);
                }
            } else {
                // Downgrade or full credit covers it — cancel current, create new
                $currentSub->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => "Plan {$direction} to {$newPlan->name}",
                ]);

                UserSubscription::create([
                    'user_id' => $user->id,
                    'subscription_plan_id' => $newPlan->id,
                    'started_at' => now(),
                    'expires_at' => now()->addDays($newPlan->duration_days ?? 30),
                    'status' => 'active',
                    'payment_method' => $validated['payment_method'],
                    'amount_paid' => 0,
                    'currency' => $newPlan->currency ?? 'UGX',
                    'auto_renew' => $currentSub->auto_renew,
                    'metadata' => [
                        'change_type' => $direction,
                        'pro_rata_credit' => $credit,
                        'previous_plan_id' => $currentPlan->id,
                    ],
                ]);
            }

            $user->notify(new SubscriptionNotification(
                SubscriptionNotification::SUBSCRIBED,
                $newPlan->name,
                ['change_type' => $direction, 'credit' => $credit]
            ));

            return response()->json([
                'success' => true,
                'message' => ucfirst($direction)." to {$newPlan->name} successful.",
                'data' => [
                    'direction' => $direction,
                    'new_plan' => $newPlan->slug,
                    'pro_rata_credit' => $credit,
                    'amount_charged' => $chargeAmount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Plan change failed. Please try again.',
            ], 500);
        }
    }

    /**
     * GET /api/user/subscription/history — subscription history
     */
    public function history(Request $request): JsonResponse
    {
        $subscriptions = UserSubscription::where('user_id', $request->user()->id)
            ->with('subscriptionPlan:id,name,slug,tier,price,currency')
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->get('per_page', 15), 50));

        $items = $subscriptions->getCollection()->map(fn (UserSubscription $sub) => [
            'id' => $sub->id,
            'plan' => $sub->subscriptionPlan ? [
                'name' => $sub->subscriptionPlan->name,
                'slug' => $sub->subscriptionPlan->slug,
                'tier' => $sub->subscriptionPlan->tier,
            ] : null,
            'status' => $sub->status,
            'amount_paid' => $sub->amount_paid,
            'currency' => $sub->currency,
            'payment_method' => $sub->payment_method,
            'started_at' => $sub->started_at?->toIso8601String(),
            'expires_at' => $sub->expires_at?->toIso8601String(),
            'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $sub->cancellation_reason,
            'auto_renew' => (bool) $sub->auto_renew,
            'created_at' => $sub->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    private function resolveCurrentDownloadsLimit(?SubscriptionPlan $plan): int
    {
        $limit = $plan?->max_downloads_per_day ?? $plan?->downloads_per_day;

        if ($limit === null || (int) $limit < 0) {
            return 0;
        }

        return (int) $limit;
    }

    private function resolveAudioQualityLimit(?SubscriptionPlan $plan): int
    {
        return (int) ($plan?->max_audio_quality_kbps ?? 128);
    }

    private function resolveUploadsLimit(?SubscriptionPlan $plan): int
    {
        $limit = $plan?->max_uploads_per_month;

        if ($limit === null || (int) $limit < 0) {
            return 0;
        }

        return (int) $limit;
    }

    private function resolveAdFree(?SubscriptionPlan $plan): bool
    {
        if ($plan === null) {
            return false;
        }

        if ($plan->ad_free !== null) {
            return (bool) $plan->ad_free;
        }

        return ! (bool) $plan->has_ads;
    }

    private function resolveOfflineAccess(?SubscriptionPlan $plan): bool
    {
        if ($plan === null) {
            return false;
        }

        if ($plan->allows_offline !== null) {
            return (bool) $plan->allows_offline;
        }

        return (bool) $plan->offline_mode;
    }
}
