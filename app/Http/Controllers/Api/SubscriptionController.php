<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        if (! $sub || ! $sub->isActive()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_subscription' => false,
                    'plan' => 'free',
                    'limits' => [
                        'downloads_per_day' => 3,
                        'audio_quality_kbps' => 128,
                        'uploads_per_month' => 0,
                    ],
                ],
            ]);
        }

        $plan = $sub->subscriptionPlan;

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
                'limits' => [
                    'downloads_per_day' => $plan?->max_downloads_per_day ?? $plan?->downloads_per_day ?? 3,
                    'audio_quality_kbps' => $plan?->max_audio_quality_kbps ?? 128,
                    'uploads_per_month' => $plan?->max_uploads_per_month ?? 0,
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
}
