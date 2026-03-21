<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Services\Revenue\StreamingRateService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
            $filters = $this->validateSubscriptionFilters($request);
            $perPage = min((int) ($filters['per_page'] ?? 20), 100);
            $subscriptions = $this->buildSubscriptionsQuery($filters)->paginate($perPage);
            $records = $subscriptions->getCollection()->map(fn (UserSubscription $sub) => $this->transformSubscription($sub))->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'records' => $records,
                    'filters' => $this->subscriptionFilterResponse($filters),
                    'export' => $this->subscriptionsExportMetadata($filters),
                ],
                'meta' => [
                    'current_page' => $subscriptions->currentPage(),
                    'last_page' => $subscriptions->lastPage(),
                    'per_page' => $subscriptions->perPage(),
                    'total' => $subscriptions->total(),
                ],
            ]);
        });
    }

    public function exportIndex(Request $request)
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        try {
            $filters = $this->validateSubscriptionFilters($request);
            $records = $this->buildSubscriptionsQuery($filters)
                ->get()
                ->map(fn (UserSubscription $sub) => $this->transformSubscription($sub));
            $export = $this->subscriptionsExportMetadata($filters);

            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, ['Subscriptions']);
            fputcsv($csv, ['Generated At', now()->toDateTimeString()]);
            fputcsv($csv, ['Status', $filters['status'] ?? '']);
            fputcsv($csv, ['Plan ID', $filters['plan_id'] ?? '']);
            fputcsv($csv, ['User ID', $filters['user_id'] ?? '']);
            fputcsv($csv, ['Search', $filters['search'] ?? '']);
            fputcsv($csv, ['Expiring Within Days', $filters['expiring_within_days'] ?? '']);
            fputcsv($csv, []);
            fputcsv($csv, ['ID', 'User Name', 'Username', 'Email', 'Plan', 'Plan Slug', 'Tier', 'Status', 'Amount Paid', 'Currency', 'Payment Method', 'Auto Renew', 'Started At', 'Expires At', 'Cancelled At', 'Days Remaining', 'Created At']);

            foreach ($records as $record) {
                fputcsv($csv, [
                    $record['id'],
                    $record['user']['name'] ?? '',
                    $record['user']['username'] ?? '',
                    $record['user']['email'] ?? '',
                    $record['plan']['name'] ?? '',
                    $record['plan']['slug'] ?? '',
                    $record['plan']['tier'] ?? '',
                    $record['status'],
                    $record['amount_paid'],
                    $record['currency'],
                    $record['payment_method'],
                    $record['auto_renew'] ? 'Yes' : 'No',
                    $record['started_at'],
                    $record['expires_at'],
                    $record['cancelled_at'],
                    $record['days_remaining'],
                    $record['created_at'],
                ]);
            }

            rewind($csv);
            $contents = stream_get_contents($csv) ?: '';
            fclose($csv);

            return response($contents, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export subscriptions.',
            ], 500);
        }
    }

    /**
     * GET /api/admin/subscriptions/rates — plan rates and platform commissions
     */
    public function rates(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => $this->buildRatesPayload(),
            ]);
        });
    }

    public function exportRates(Request $request)
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        try {
            $payload = $this->buildRatesPayload();
            $csv = fopen('php://temp', 'r+');

            fputcsv($csv, ['Subscription Rates']);
            fputcsv($csv, ['Generated At', now()->toDateTimeString()]);
            fputcsv($csv, []);
            fputcsv($csv, ['Platform Commissions']);
            foreach ($payload['platform_commissions'] as $key => $value) {
                fputcsv($csv, [$key, $value]);
            }
            fputcsv($csv, []);
            fputcsv($csv, ['Plans']);
            fputcsv($csv, [
                'ID',
                'Name',
                'Slug',
                'Tier',
                'Currency',
                'Price Monthly',
                'Price Yearly',
                'Is Active',
                'Stream Rate UGX',
                'Credit To UGX Rate',
                'Event Commission Percent',
                'Event Processing Fee Percent',
                'Effective Stream Rate UGX',
                'Streaming Commission Percent',
                'Estimated Platform Fee UGX',
                'Estimated Net Per Stream UGX',
                'Rate Source',
            ]);

            foreach ($payload['records'] as $plan) {
                fputcsv($csv, [
                    $plan['id'],
                    $plan['name'],
                    $plan['slug'],
                    $plan['tier'],
                    $plan['currency'],
                    $plan['price_monthly'],
                    $plan['price_yearly'],
                    $plan['is_active'] ? 'Yes' : 'No',
                    $plan['rates']['stream_rate_ugx'],
                    $plan['rates']['credit_to_ugx_rate'],
                    $plan['rates']['event_platform_commission_percent'] ?? '',
                    $plan['rates']['event_processing_fee_percent'] ?? '',
                    $plan['rates']['effective']['effective_stream_rate_ugx'] ?? '',
                    $plan['rates']['effective']['streaming_commission_percent'] ?? '',
                    $plan['rates']['effective']['estimated_platform_fee_ugx'] ?? '',
                    $plan['rates']['effective']['estimated_net_per_stream_ugx'] ?? '',
                    $plan['rates']['effective']['rate_source'] ?? '',
                ]);
            }

            rewind($csv);
            $contents = stream_get_contents($csv) ?: '';
            fclose($csv);

            return response($contents, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$payload['export']['filename'].'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export subscription rates.',
            ], 500);
        }
    }

    /**
     * PUT /api/admin/subscriptions/rates — bulk update plan rates and commissions
     */
    public function updateRates(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'plans' => 'sometimes|array',
                'plans.*.id' => 'required_with:plans|integer|exists:subscription_plans,id',
                'plans.*.stream_rate_ugx' => 'nullable|numeric|min:0',
                'plans.*.credit_to_ugx_rate' => 'nullable|numeric|gt:0',
                'plans.*.event_platform_commission_percent' => 'nullable|numeric|min:0|max:100',
                'plans.*.event_processing_fee_percent' => 'nullable|numeric|min:0|max:100',
                'platform_commissions' => 'sometimes|array',
                'platform_commissions.streaming_percent' => 'sometimes|numeric|min:0|max:100',
                'platform_commissions.subscription_percent' => 'sometimes|numeric|min:0|max:100',
                'platform_commissions.credit_conversion_percent' => 'sometimes|numeric|min:0|max:100',
                'platform_commissions.withdrawal_percent' => 'sometimes|numeric|min:0|max:100',
                'platform_commissions.distribution_percent' => 'sometimes|numeric|min:0|max:100',
                'platform_commissions.store_percent' => 'sometimes|numeric|min:0|max:100',
            ]);

            foreach ($validated['plans'] ?? [] as $planData) {
                $plan = SubscriptionPlan::findOrFail($planData['id']);
                $metadata = $plan->metadata ?? [];

                $metadata['stream_rate_ugx'] = array_key_exists('stream_rate_ugx', $planData)
                    ? $this->normalizeDecimal($planData['stream_rate_ugx'])
                    : Arr::get($metadata, 'stream_rate_ugx');

                $metadata['credit_to_ugx_rate'] = array_key_exists('credit_to_ugx_rate', $planData)
                    ? $this->normalizeDecimal($planData['credit_to_ugx_rate'], 4)
                    : Arr::get($metadata, 'credit_to_ugx_rate');

                $metadata['event_platform_commission_percent'] = array_key_exists('event_platform_commission_percent', $planData)
                    ? $this->normalizeDecimal($planData['event_platform_commission_percent'])
                    : Arr::get($metadata, 'event_platform_commission_percent');

                $metadata['event_processing_fee_percent'] = array_key_exists('event_processing_fee_percent', $planData)
                    ? $this->normalizeDecimal($planData['event_processing_fee_percent'])
                    : Arr::get($metadata, 'event_processing_fee_percent');

                $plan->update(['metadata' => $metadata]);
            }

            if (array_key_exists('platform_commissions', $validated)) {
                Setting::set(
                    'platform_commissions',
                    $this->normalizeCommissionSettings($validated['platform_commissions']),
                    Setting::TYPE_JSON,
                    Setting::GROUP_PAYMENTS
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Subscription rates updated successfully.',
                'data' => $this->buildRatesPayload(),
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
            $records = SubscriptionPlan::orderBy('sort_order')
                ->get()
                ->map(fn (SubscriptionPlan $plan) => $this->transformPlan($plan))
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'records' => $records,
                    'filters' => [],
                    'export' => $this->subscriptionPlansExportMetadata(),
                ],
                'legacy_data' => $records,
            ]);
        });
    }

    public function exportPlans(Request $request)
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        try {
            $records = SubscriptionPlan::orderBy('sort_order')
                ->get()
                ->map(fn (SubscriptionPlan $plan) => $this->transformPlan($plan))
                ->values();
            $export = $this->subscriptionPlansExportMetadata();

            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, ['Subscription Plans']);
            fputcsv($csv, ['Generated At', now()->toDateTimeString()]);
            fputcsv($csv, []);
            fputcsv($csv, ['ID', 'Name', 'Slug', 'Tier', 'Is Active', 'Price Monthly', 'Price Yearly', 'Stream Rate UGX', 'Credit To UGX Rate', 'Event Commission Percent', 'Event Processing Fee Percent', 'Effective Stream Rate UGX', 'Estimated Net Per Stream UGX', 'Rate Source']);

            foreach ($records as $plan) {
                fputcsv($csv, [
                    $plan['id'],
                    $plan['name'] ?? '',
                    $plan['slug'] ?? '',
                    $plan['tier'] ?? '',
                    ! empty($plan['is_active']) ? 'Yes' : 'No',
                    $plan['price_monthly'] ?? '',
                    $plan['price_yearly'] ?? '',
                    $plan['rates']['stream_rate_ugx'] ?? '',
                    $plan['rates']['credit_to_ugx_rate'] ?? '',
                    $plan['rates']['event_platform_commission_percent'] ?? '',
                    $plan['rates']['event_processing_fee_percent'] ?? '',
                    $plan['rates']['effective']['effective_stream_rate_ugx'] ?? '',
                    $plan['rates']['effective']['estimated_net_per_stream_ugx'] ?? '',
                    $plan['rates']['effective']['rate_source'] ?? '',
                ]);
            }

            rewind($csv);
            $contents = stream_get_contents($csv) ?: '';
            fclose($csv);

            return response($contents, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export subscription plans.',
            ], 500);
        }
    }

    /**
     * POST /api/admin/subscription-plans — create plan
     */
    public function storePlan(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $validated = $this->validatePlanPayload($request, true);
            $plan = SubscriptionPlan::create($this->buildPlanPayload($validated));

            return response()->json([
                'success' => true,
                'data' => $this->transformPlan($plan->fresh()),
                'message' => "Plan \"{$plan->name}\" created successfully.",
            ], 201);
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
            $validated = $this->validatePlanPayload($request, false, $plan);
            $plan->update($this->buildPlanPayload($validated, $plan));

            return response()->json([
                'success' => true,
                'data' => $this->transformPlan($plan->fresh()),
                'message' => "Plan \"{$plan->name}\" updated successfully.",
            ]);
        });
    }

    private function validateSubscriptionFilters(Request $request): array
    {
        return $request->validate([
            'status' => 'nullable|string|max:50',
            'plan_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'search' => 'nullable|string|max:255',
            'expiring_within_days' => 'nullable|integer|min:1|max:365',
            'sort_by' => 'nullable|string|max:50',
            'sort_order' => 'nullable|string|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }

    private function validatePlanPayload(Request $request, bool $isCreate, ?SubscriptionPlan $plan = null): array
    {
        $nameRule = $isCreate ? 'required' : 'sometimes';
        $descriptionRule = $isCreate ? 'required' : 'sometimes';
        $tierRule = $isCreate ? 'required' : 'sometimes';
        $typeRule = $isCreate ? 'required' : 'sometimes';

        return $request->validate([
            'name' => "{$nameRule}|string|max:100",
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:subscription_plans,slug'.($plan ? ','.$plan->id : ''),
            ],
            'description' => "{$descriptionRule}|string|max:1000",
            'tier' => "{$tierRule}|string|max:50",
            'type' => "{$typeRule}|string|max:50",
            'price' => 'sometimes|numeric|min:0',
            'price_monthly' => 'sometimes|numeric|min:0',
            'price_yearly' => 'sometimes|numeric|min:0',
            'price_local' => 'sometimes|numeric|min:0',
            'price_usd' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'interval' => 'sometimes|string|max:20',
            'interval_count' => 'sometimes|integer|min:1|max:24',
            'trial_days' => 'sometimes|integer|min:0|max:365',
            'duration_days' => 'sometimes|integer|min:1|max:365',
            'region' => 'sometimes|nullable|string|max:20',
            'features' => 'sometimes|array',
            'features.*' => 'string|max:255',
            'max_downloads_per_day' => 'sometimes|nullable|integer|min:0',
            'max_uploads_per_month' => 'sometimes|nullable|integer|min:0',
            'max_audio_quality_kbps' => 'sometimes|integer|in:128,192,256,320',
            'has_ads' => 'sometimes|boolean',
            'offline_mode' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'is_visible' => 'sometimes|boolean',
            'is_featured' => 'sometimes|boolean',
            'is_popular' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'rates' => 'sometimes|array',
            'rates.stream_rate_ugx' => 'nullable|numeric|min:0',
            'rates.credit_to_ugx_rate' => 'nullable|numeric|gt:0',
            'rates.event_platform_commission_percent' => 'nullable|numeric|min:0|max:100',
            'rates.event_processing_fee_percent' => 'nullable|numeric|min:0|max:100',
        ]);
    }

    private function buildPlanPayload(array $validated, ?SubscriptionPlan $plan = null): array
    {
        $payload = Arr::except($validated, ['rates']);

        if (array_key_exists('name', $validated) && ! array_key_exists('slug', $validated) && ! $plan) {
            $payload['slug'] = $this->generateUniqueSlug($validated['name']);
        }

        if (array_key_exists('slug', $validated)) {
            $payload['slug'] = $validated['slug'] ?: $this->generateUniqueSlug($validated['name'] ?? $plan?->name ?? 'plan', $plan?->id);
        }

        if (! $plan) {
            $payload['uuid'] = (string) Str::uuid();
        }

        $monthly = array_key_exists('price_monthly', $validated)
            ? (float) $validated['price_monthly']
            : (float) ($plan?->price_monthly ?? 0);
        $local = array_key_exists('price_local', $validated)
            ? (float) $validated['price_local']
            : (float) ($plan?->price_local ?? $monthly);
        $basePrice = array_key_exists('price', $validated)
            ? (float) $validated['price']
            : (array_key_exists('price_local', $validated) ? $local : ($plan?->price ?? $local ?: $monthly));

        $payload['price'] = $basePrice ?: ($local ?: $monthly);

        if (array_key_exists('features', $validated)) {
            $payload['features'] = collect($validated['features'])
                ->map(fn ($feature) => trim((string) $feature))
                ->filter()
                ->values()
                ->all();
        }

        $downloadLimitProvided = array_key_exists('max_downloads_per_day', $validated);
        $uploadLimitProvided = array_key_exists('max_uploads_per_month', $validated);
        $qualityProvided = array_key_exists('max_audio_quality_kbps', $validated);

        if ($downloadLimitProvided) {
            $payload['downloads_per_day'] = $validated['max_downloads_per_day'];
            $payload['download_limit'] = $validated['max_downloads_per_day'];
        }

        if (array_key_exists('offline_mode', $validated)) {
            $payload['allows_offline'] = (bool) $validated['offline_mode'];
        }

        if (array_key_exists('has_ads', $validated)) {
            $payload['ad_free'] = ! (bool) $validated['has_ads'];
        }

        if (
            $downloadLimitProvided
            || $uploadLimitProvided
            || $qualityProvided
            || array_key_exists('offline_mode', $validated)
            || array_key_exists('has_ads', $validated)
        ) {
            $payload['limits'] = array_merge($plan?->limits ?? [], [
                'downloads_per_day' => $downloadLimitProvided
                    ? $validated['max_downloads_per_day']
                    : Arr::get($plan?->limits ?? [], 'downloads_per_day'),
                'max_downloads_per_day' => $downloadLimitProvided
                    ? $validated['max_downloads_per_day']
                    : Arr::get($plan?->limits ?? [], 'max_downloads_per_day'),
                'uploads_per_month' => $uploadLimitProvided
                    ? $validated['max_uploads_per_month']
                    : Arr::get($plan?->limits ?? [], 'uploads_per_month'),
                'max_uploads_per_month' => $uploadLimitProvided
                    ? $validated['max_uploads_per_month']
                    : Arr::get($plan?->limits ?? [], 'max_uploads_per_month'),
                'audio_quality_kbps' => $qualityProvided
                    ? $validated['max_audio_quality_kbps']
                    : Arr::get($plan?->limits ?? [], 'audio_quality_kbps'),
                'max_audio_quality_kbps' => $qualityProvided
                    ? $validated['max_audio_quality_kbps']
                    : Arr::get($plan?->limits ?? [], 'max_audio_quality_kbps'),
                'offline_mode' => array_key_exists('offline_mode', $validated)
                    ? (bool) $validated['offline_mode']
                    : Arr::get($plan?->limits ?? [], 'offline_mode'),
                'has_ads' => array_key_exists('has_ads', $validated)
                    ? (bool) $validated['has_ads']
                    : Arr::get($plan?->limits ?? [], 'has_ads'),
                'ad_free' => array_key_exists('has_ads', $validated)
                    ? ! (bool) $validated['has_ads']
                    : Arr::get($plan?->limits ?? [], 'ad_free'),
            ]);
        }

        if (array_key_exists('rates', $validated)) {
            $payload['metadata'] = $this->mergePlanRatesIntoMetadata($plan ?? new SubscriptionPlan, $validated['rates']);
        }

        return $payload;
    }

    private function generateUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($value);
        $rootSlug = $baseSlug !== '' ? $baseSlug : 'plan';
        $slug = $rootSlug;
        $suffix = 2;

        while (
            SubscriptionPlan::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$rootSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function buildSubscriptionsQuery(array $filters)
    {
        $query = UserSubscription::with(['user:id,name,username,email', 'subscriptionPlan:id,name,slug,tier,price,currency']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['plan_id'])) {
            $query->where('subscription_plan_id', $filters['plan_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['search'])) {
            $escaped = addcslashes((string) $filters['search'], '%_');
            $query->whereHas('user', function ($q) use ($escaped) {
                $q->where('name', 'LIKE', "%{$escaped}%")
                    ->orWhere('email', 'LIKE', "%{$escaped}%")
                    ->orWhere('username', 'LIKE', "%{$escaped}%");
            });
        }

        if (! empty($filters['expiring_within_days'])) {
            $days = (int) $filters['expiring_within_days'];
            $query->where('status', 'active')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays($days));
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $allowed = ['created_at', 'expires_at', 'started_at', 'amount_paid', 'status'];
        if (in_array($sortBy, $allowed, true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $query->latest();
        }

        return $query;
    }

    private function transformSubscription(UserSubscription $sub): array
    {
        return [
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
        ];
    }

    private function subscriptionFilterResponse(array $filters): array
    {
        return [
            'status' => $filters['status'] ?? null,
            'plan_id' => $filters['plan_id'] ?? null,
            'user_id' => $filters['user_id'] ?? null,
            'search' => $filters['search'] ?? null,
            'expiring_within_days' => $filters['expiring_within_days'] ?? null,
            'sort_by' => $filters['sort_by'] ?? 'created_at',
            'sort_order' => $filters['sort_order'] ?? 'desc',
        ];
    }

    private function subscriptionsExportMetadata(array $filters): array
    {
        $query = array_filter($this->subscriptionFilterResponse($filters), static fn ($value) => $value !== null && $value !== '');
        $filenameParts = ['subscriptions'];

        if (! empty($filters['status'])) {
            $filenameParts[] = $filters['status'];
        }

        if (! empty($filters['plan_id'])) {
            $filenameParts[] = 'plan_'.$filters['plan_id'];
        }

        $filenameParts[] = now()->format('Y-m-d');

        return [
            'format' => 'csv',
            'filename' => implode('_', $filenameParts).'.csv',
            'url' => url('/api/admin/subscriptions/export').(! empty($query) ? '?'.http_build_query($query) : ''),
            'filters' => $this->subscriptionFilterResponse($filters),
        ];
    }

    private function buildRatesPayload(): array
    {
        $records = $this->buildRateRecords();

        return [
            'records' => $records,
            'plans' => $records,
            'filters' => [],
            'export' => $this->ratesExportMetadata(),
            'platform_commissions' => $this->getPlatformCommissions(),
            'streaming_configuration' => app(StreamingRateService::class)->getStreamingConfigurationSummary(),
        ];
    }

    private function buildRateRecords(): Collection
    {
        return SubscriptionPlan::orderBy('sort_order')
            ->get()
            ->map(function (SubscriptionPlan $plan) {
                $rates = $this->extractPlanRates($plan);
                $rates['effective'] = app(StreamingRateService::class)->describePlan($plan);

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'tier' => $plan->tier,
                    'currency' => $plan->currency,
                    'price_monthly' => $plan->price_monthly,
                    'price_yearly' => $plan->price_yearly,
                    'is_active' => (bool) $plan->is_active,
                    'rates' => $rates,
                ];
            })
            ->values();
    }

    private function ratesExportMetadata(): array
    {
        return [
            'format' => 'csv',
            'filename' => 'subscription_rates_'.now()->format('Y-m-d').'.csv',
            'url' => url('/api/admin/subscriptions/rates/export'),
            'filters' => [],
        ];
    }

    private function subscriptionPlansExportMetadata(): array
    {
        return [
            'format' => 'csv',
            'filename' => 'subscription_plans_'.now()->format('Y-m-d').'.csv',
            'url' => url('/api/admin/subscription-plans/export'),
            'filters' => [],
        ];
    }

    private function transformPlan(SubscriptionPlan $plan): array
    {
        $data = $plan->toArray();
        $data['rates'] = $this->extractPlanRates($plan);
        $data['rates']['effective'] = app(StreamingRateService::class)->describePlan($plan);

        return $data;
    }

    private function extractPlanRates(SubscriptionPlan $plan): array
    {
        $metadata = $plan->metadata ?? [];

        return [
            'stream_rate_ugx' => Arr::get($metadata, 'stream_rate_ugx'),
            'credit_to_ugx_rate' => Arr::get($metadata, 'credit_to_ugx_rate'),
            'event_platform_commission_percent' => Arr::get($metadata, 'event_platform_commission_percent'),
            'event_processing_fee_percent' => Arr::get($metadata, 'event_processing_fee_percent'),
        ];
    }

    private function mergePlanRatesIntoMetadata(SubscriptionPlan $plan, array $rates): array
    {
        $metadata = $plan->metadata ?? [];

        if (array_key_exists('stream_rate_ugx', $rates)) {
            $metadata['stream_rate_ugx'] = $this->normalizeDecimal($rates['stream_rate_ugx']);
        }

        if (array_key_exists('credit_to_ugx_rate', $rates)) {
            $metadata['credit_to_ugx_rate'] = $this->normalizeDecimal($rates['credit_to_ugx_rate'], 4);
        }

        if (array_key_exists('event_platform_commission_percent', $rates)) {
            $metadata['event_platform_commission_percent'] = $this->normalizeDecimal($rates['event_platform_commission_percent']);
        }

        if (array_key_exists('event_processing_fee_percent', $rates)) {
            $metadata['event_processing_fee_percent'] = $this->normalizeDecimal($rates['event_processing_fee_percent']);
        }

        return $metadata;
    }

    private function getPlatformCommissions(): array
    {
        return $this->normalizeCommissionSettings(Setting::get('platform_commissions', [
            'streaming_percent' => 15,
            'subscription_percent' => 0,
            'credit_conversion_percent' => 0,
            'withdrawal_percent' => 0,
            'distribution_percent' => 0,
            'store_percent' => 5,
        ]));
    }

    private function normalizeCommissionSettings(array $settings): array
    {
        $defaults = [
            'streaming_percent' => 15,
            'subscription_percent' => 0,
            'credit_conversion_percent' => 0,
            'withdrawal_percent' => 0,
            'distribution_percent' => 0,
            'store_percent' => 5,
        ];

        $merged = array_merge($defaults, $settings);

        foreach ($merged as $key => $value) {
            $merged[$key] = $this->normalizeDecimal($value);
        }

        return $merged;
    }

    private function normalizeDecimal(mixed $value, int $precision = 2): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, $precision, '.', '');
    }
}
