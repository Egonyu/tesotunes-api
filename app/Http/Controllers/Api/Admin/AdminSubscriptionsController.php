<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSubscriptionsController extends Controller
{
    use HandlesApiErrors;

    protected function ensureAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access is required for this action.',
            ], 403);
        }

        return null;
    }

    /**
     * GET /api/admin/subscriptions/stats — subscription analytics
     */
    public function stats(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () {
            $total = UserSubscription::count();
            $active = UserSubscription::where('status', 'active')
                ->where('expires_at', '>', now())
                ->count();
            $expired = UserSubscription::where('status', 'expired')
                ->orWhere(function ($q) {
                    $q->where('status', 'active')->where('expires_at', '<=', now());
                })
                ->count();
            $cancelled = UserSubscription::whereIn('status', ['cancelled', 'canceled'])->count();
            $expiringSoon = UserSubscription::where('status', 'active')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(7))
                ->count();

            $mrr = UserSubscription::where('status', 'active')
                ->where('expires_at', '>', now())
                ->join('subscription_plans', 'user_subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
                ->selectRaw('COALESCE(SUM(subscription_plans.price_monthly), 0) as mrr')
                ->value('mrr');

            $planDistribution = UserSubscription::where('user_subscriptions.status', 'active')
                ->where('expires_at', '>', now())
                ->join('subscription_plans', 'user_subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
                ->selectRaw('subscription_plans.name, subscription_plans.slug, COUNT(*) as count')
                ->groupBy('subscription_plans.name', 'subscription_plans.slug')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'active' => $active,
                    'expired' => $expired,
                    'cancelled' => $cancelled,
                    'expiring_soon' => $expiringSoon,
                    'mrr' => (float) $mrr,
                    'currency' => 'UGX',
                    'plan_distribution' => $planDistribution,
                ],
            ]);
        });
    }

    /**
     * GET /api/admin/subscriptions — list all subscriptions with filters
     */
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $query = UserSubscription::with(['user:id,name,username,email', 'subscriptionPlan:id,name,slug,tier,price,currency']);

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('plan_id')) {
                $query->where('subscription_plan_id', $request->plan_id);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('search')) {
                $escaped = addcslashes($request->search, '%_');
                $query->whereHas('user', function ($q) use ($escaped) {
                    $q->where('name', 'LIKE', "%{$escaped}%")
                        ->orWhere('email', 'LIKE', "%{$escaped}%")
                        ->orWhere('username', 'LIKE', "%{$escaped}%");
                });
            }

            if ($request->filled('expiring_within_days')) {
                $days = (int) $request->expiring_within_days;
                $query->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->where('expires_at', '<=', now()->addDays($days));
            }

            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $allowed = ['created_at', 'expires_at', 'started_at', 'amount_paid', 'status'];
            if (in_array($sortBy, $allowed)) {
                $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
            } else {
                $query->latest();
            }

            $perPage = min((int) $request->get('per_page', 20), 100);
            $subscriptions = $query->paginate($perPage);

            $items = $subscriptions->getCollection()->map(fn (UserSubscription $sub) => [
                'id' => $sub->id,
                'user' => $sub->user ? [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'username' => $sub->user->username,
                    'email' => $sub->user->email,
                ] : null,
                'plan' => $sub->subscriptionPlan ? [
                    'id' => $sub->subscriptionPlan->id,
                    'name' => $sub->subscriptionPlan->name,
                    'slug' => $sub->subscriptionPlan->slug,
                    'tier' => $sub->subscriptionPlan->tier,
                ] : null,
                'status' => $sub->status,
                'amount_paid' => $sub->amount_paid,
                'currency' => $sub->currency,
                'payment_method' => $sub->payment_method,
                'auto_renew' => (bool) $sub->auto_renew,
                'started_at' => $sub->started_at?->toIso8601String(),
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
                'days_remaining' => $sub->isActive() ? $sub->daysUntilExpiry() : 0,
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
        });
    }

    /**
     * GET /api/admin/subscriptions/{id} — single subscription detail
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $sub = UserSubscription::with(['user:id,name,username,email', 'subscriptionPlan'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $sub->id,
                    'user' => $sub->user ? [
                        'id' => $sub->user->id,
                        'name' => $sub->user->name,
                        'username' => $sub->user->username,
                        'email' => $sub->user->email,
                    ] : null,
                    'plan' => $sub->subscriptionPlan,
                    'status' => $sub->status,
                    'amount_paid' => $sub->amount_paid,
                    'currency' => $sub->currency,
                    'payment_method' => $sub->payment_method,
                    'transaction_reference' => $sub->transaction_reference,
                    'auto_renew' => (bool) $sub->auto_renew,
                    'started_at' => $sub->started_at?->toIso8601String(),
                    'expires_at' => $sub->expires_at?->toIso8601String(),
                    'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
                    'cancellation_reason' => $sub->cancellation_reason,
                    'extended_at' => $sub->extended_at?->toIso8601String(),
                    'extension_reason' => $sub->extension_reason,
                    'days_remaining' => $sub->isActive() ? $sub->daysUntilExpiry() : 0,
                    'metadata' => $sub->metadata,
                    'created_at' => $sub->created_at?->toIso8601String(),
                    'updated_at' => $sub->updated_at?->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * POST /api/admin/subscriptions/grant — grant subscription to user (no payment)
     */
    public function grant(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'plan_id' => 'required|exists:subscription_plans,id',
                'days' => 'required|integer|min:1|max:365',
                'reason' => 'required|string|max:500',
            ]);

            $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

            // Cancel existing active subscriptions for this user
            UserSubscription::where('user_id', $validated['user_id'])
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Replaced by admin-granted subscription',
                ]);

            $sub = UserSubscription::create([
                'user_id' => $validated['user_id'],
                'subscription_plan_id' => $plan->id,
                'started_at' => now(),
                'expires_at' => now()->addDays($validated['days']),
                'status' => 'active',
                'amount_paid' => 0,
                'currency' => $plan->currency ?? 'UGX',
                'payment_method' => 'admin_grant',
                'auto_renew' => false,
                'metadata' => [
                    'granted_by' => $request->user()->id,
                    'reason' => $validated['reason'],
                ],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'subscription_id' => $sub->id,
                    'plan' => $plan->name,
                    'expires_at' => $sub->expires_at->toIso8601String(),
                ],
                'message' => "Granted {$plan->name} subscription for {$validated['days']} days.",
            ]);
        });
    }

    /**
     * POST /api/admin/subscriptions/{id}/revoke — revoke / force-cancel a subscription
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request, $id) {
            $sub = UserSubscription::findOrFail($id);

            if ($sub->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription is not active.',
                ], 422);
            }

            $validated = $request->validate([
                'reason' => 'required|string|max:500',
            ]);

            $sub->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Admin revoked: '.$validated['reason'],
                'metadata' => array_merge($sub->metadata ?? [], [
                    'revoked_by' => $request->user()->id,
                ]),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription revoked successfully.',
            ]);
        });
    }

    /**
     * GET /api/admin/subscription-plans — manage plans (includes hidden)
     */
    public function plansList(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () {
            $plans = SubscriptionPlan::orderBy('sort_order')->get();

            return response()->json([
                'success' => true,
                'data' => $plans,
            ]);
        });
    }

    /**
     * PUT /api/admin/subscription-plans/{id} — update plan
     */
    public function updatePlan(Request $request, int $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request, $id) {
            $plan = SubscriptionPlan::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'description' => 'sometimes|string|max:1000',
                'price' => 'sometimes|numeric|min:0',
                'price_monthly' => 'sometimes|numeric|min:0',
                'price_yearly' => 'sometimes|numeric|min:0',
                'price_local' => 'sometimes|numeric|min:0',
                'trial_days' => 'sometimes|integer|min:0',
                'duration_days' => 'sometimes|integer|min:1|max:365',
                'features' => 'sometimes|array',
                'max_downloads_per_day' => 'sometimes|nullable|integer',
                'max_uploads_per_month' => 'sometimes|nullable|integer',
                'max_audio_quality_kbps' => 'sometimes|integer|in:128,192,256,320',
                'has_ads' => 'sometimes|boolean',
                'offline_mode' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean',
                'is_visible' => 'sometimes|boolean',
                'is_featured' => 'sometimes|boolean',
                'is_popular' => 'sometimes|boolean',
                'sort_order' => 'sometimes|integer|min:0',
            ]);

            $plan->update($validated);

            return response()->json([
                'success' => true,
                'data' => $plan->fresh(),
                'message' => "Plan \"{$plan->name}\" updated successfully.",
            ]);
        });
    }
}
