<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Notification as AppNotification;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
// ZengaPay is the sole payment provider
use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service class for handling payment processing and subscription management
 *
 * This service manages:
 * - Payment processing for subscriptions
 * - Mobile money integration (MTN, Airtel)
 * - Subscription lifecycle management
 * - Payout processing for artists
 * - Revenue analytics and reporting
 * - Refund processing
 * - Payment method validation
 */
class PaymentService
{
    // Payment statuses
    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_REFUNDED = 'refunded';

    // Payment method — ZengaPay only
    const METHOD_ZENGAPAY = 'zengapay';

    // Payment method types (for payment_method column in DB)
    const PAYMENT_METHOD_ZENGAPAY = 'zengapay';

    const PAYMENT_METHOD_PLATFORM_CREDITS = 'platform_credits';

    // Payment provider — ZengaPay only
    const PROVIDER_ZENGAPAY = 'zengapay';

    // Subscription statuses
    const SUBSCRIPTION_ACTIVE = 'active';

    const SUBSCRIPTION_EXPIRED = 'expired';

    const SUBSCRIPTION_CANCELLED = 'cancelled';

    const SUBSCRIPTION_PAUSED = 'paused';

    public function __construct()
    {
        // ZengaPay is the sole payment gateway
    }

    /**
     * Process subscription payment
     */
    public function processSubscriptionPayment(
        User $user,
        SubscriptionPlan $plan,
        string $paymentMethod,
        array $paymentData = []
    ): array {
        DB::beginTransaction();

        try {
            // Validate payment method and data
            $this->validatePaymentData($paymentMethod, $paymentData);

            // Resolve amount: prefer price_local for the region, fall back to price_monthly, then price
            $amount = $plan->price_local ?: ($plan->price_monthly ?: $plan->price);

            // Create payment record
            $payment = $this->createPayment([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'amount' => $amount,
                'currency' => $plan->currency ?? 'UGX',
                'payment_method' => $paymentMethod,
                'description' => "Subscription: {$plan->name}",
                'metadata' => $paymentData,
            ]);

            // Process payment based on method
            $paymentResult = $this->processPayment($payment, $paymentData);

            if ($paymentResult['success']) {
                // Update payment status to completed (use forceFill for guarded fields)
                $payment->forceFill([
                    'status' => self::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'transaction_id' => $paymentResult['transaction_id'] ?? $payment->transaction_id,
                ])->save();

                // Update fillable fields separately
                $payment->update([
                    'provider_reference' => $paymentResult['reference'] ?? null,
                ]);

                // Create or update subscription
                $subscription = $this->createSubscription($user, $plan, $payment);

                DB::commit();

                return [
                    'success' => true,
                    'payment_id' => $payment->id,
                    'subscription_id' => $subscription->id,
                    'message' => 'Subscription payment processed successfully',
                    'payment_status' => $payment->status,
                    'subscription_ends_at' => $subscription->ends_at,
                ];
            } else {
                // Update payment status to failed before throwing exception
                $payment->forceFill([
                    'status' => self::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_reason' => $paymentResult['message'] ?? 'Payment failed',
                ])->save();

                DB::commit(); // Commit the failed payment record

                return [
                    'success' => false,
                    'message' => $paymentResult['message'] ?? 'Payment failed',
                    'payment_id' => $payment->id,
                ];
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Subscription payment failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Process one-time payment
     */
    public function processOneTimePayment(
        User $user,
        float $amount,
        string $currency,
        string $paymentMethod,
        string $description,
        array $paymentData = []
    ): array {
        DB::beginTransaction();

        try {
            $this->validatePaymentData($paymentMethod, $paymentData);

            $payment = $this->createPayment([
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $paymentMethod,
                'description' => $description,
                'metadata' => $paymentData,
            ]);

            $paymentResult = $this->processPayment($payment, $paymentData);

            if ($paymentResult['success']) {
                // Update payment status to completed (use forceFill for guarded fields)
                $payment->forceFill([
                    'status' => self::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'transaction_id' => $paymentResult['transaction_id'] ?? $payment->transaction_id,
                ])->save();

                // Update fillable fields separately
                $payment->update([
                    'provider_reference' => $paymentResult['reference'] ?? null,
                ]);

                DB::commit();

                return [
                    'success' => true,
                    'payment_id' => $payment->id,
                    'message' => 'Payment processed successfully',
                    'payment_status' => $payment->status,
                ];
            } else {
                throw new Exception($paymentResult['message']);
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('One-time payment failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Process refund
     */
    public function processRefund(Payment $payment, ?float $amount = null, string $reason = ''): array
    {
        if ($payment->status !== self::STATUS_COMPLETED) {
            throw new Exception('Only completed payments can be refunded');
        }

        $refundAmount = $amount ?? $payment->amount;

        if ($refundAmount > $payment->amount) {
            throw new Exception('Refund amount cannot exceed original payment amount');
        }

        DB::beginTransaction();

        try {
            // Process refund based on payment method
            $refundResult = $this->processMethodRefund($payment, $refundAmount);

            if ($refundResult['success']) {
                // Update payment record
                $metadata = $payment->metadata ?? [];
                $metadata['refund_amount'] = $refundAmount;
                $metadata['refund_reason'] = $reason;

                $payment->update([
                    'metadata' => $metadata,
                    'refunded_at' => now(),
                ]);

                $payment->markAsRefunded();

                // Cancel associated subscription if applicable
                if ($payment->userSubscription) {
                    $this->cancelSubscription($payment->userSubscription, 'payment_refunded');
                }

                // Notify user
                $this->notifyUserOfRefund($payment, $refundAmount, $reason);

                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Refund processed successfully',
                    'refund_amount' => $refundAmount,
                ];
            } else {
                throw new Exception($refundResult['message']);
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Refund processing failed', [
                'payment_id' => $payment->id,
                'amount' => $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create and process artist payout
     */
    public function processArtistPayout(Artist $artist, float $amount, string $method = 'mobile_money'): array
    {
        // Validate artist eligibility
        if (! $this->isArtistEligibleForPayout($artist, $amount)) {
            throw new Exception('Artist not eligible for payout or insufficient balance');
        }

        DB::beginTransaction();

        try {
            $payout = Payout::create([
                'artist_id' => $artist->id,
                'amount' => $amount,
                'currency' => 'UGX', // Default currency
                'method' => $method,
                'status' => 'pending',
                'requested_at' => now(),
                'metadata' => [
                    'balance_before' => $artist->earnings_balance,
                    'phone_number' => $artist->payout_phone_number,
                ],
            ]);

            // Deduct from artist balance
            $artist->decrement('earnings_balance', $amount);

            // Queue payout processing job
            dispatch(new \App\Jobs\ProcessArtistPayout($payout));

            DB::commit();

            return [
                'success' => true,
                'payout_id' => $payout->id,
                'message' => 'Payout request submitted successfully',
                'processing_time' => '1-3 business days',
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(UserSubscription $subscription, string $reason = ''): array
    {
        if ($subscription->status === self::SUBSCRIPTION_CANCELLED) {
            throw new Exception('Subscription is already cancelled');
        }

        $subscription->update([
            'status' => self::SUBSCRIPTION_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'auto_renew' => false,
        ]);

        // Notify user
        $this->notifyUserOfCancellation($subscription, $reason);

        return [
            'success' => true,
            'message' => 'Subscription cancelled successfully',
            'effective_date' => $subscription->ends_at,
        ];
    }

    /**
     * Extend subscription
     */
    public function extendSubscription(
        UserSubscription $subscription,
        int $days,
        string $reason = ''
    ): UserSubscription {
        $newEndDate = $subscription->expires_at->addDays($days);

        $subscription->update([
            'expires_at' => $newEndDate,
            'extension_reason' => $reason,
            'extended_at' => now(),
        ]);

        // Notify user
        $this->notifyUserOfExtension($subscription, $days, $reason);

        return $subscription->fresh();
    }

    /**
     * Get payment analytics
     */
    public function getPaymentAnalytics(array $filters = []): array
    {
        $query = Payment::query();

        // Apply date filters
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        // Apply status filter
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $payments = $query->get();

        return [
            'total_payments' => $payments->count(),
            'completed_payments' => $payments->where('status', self::STATUS_COMPLETED)->count(),
            'failed_payments' => $payments->where('status', self::STATUS_FAILED)->count(),
            'pending_payments' => $payments->where('status', self::STATUS_PENDING)->count(),
            'total_revenue' => $payments->where('status', self::STATUS_COMPLETED)->sum('amount'),
            'average_payment' => $payments->where('status', self::STATUS_COMPLETED)->avg('amount'),
            'payment_methods' => $payments->groupBy('payment_method')->map->count(),
            'daily_revenue' => $this->getDailyRevenue($payments),
            'currency_breakdown' => $payments->groupBy('currency')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'total' => $group->where('status', self::STATUS_COMPLETED)->sum('amount'),
                ];
            }),
        ];
    }

    /**
     * Get subscription analytics
     */
    public function getSubscriptionAnalytics(): array
    {
        $subscriptions = UserSubscription::with('subscriptionPlan')->get();

        $expiringThreshold = now()->addDays(7);

        return [
            'total_subscriptions' => $subscriptions->count(),
            'active_subscriptions' => $subscriptions->where('status', self::SUBSCRIPTION_ACTIVE)->count(),
            'expired_subscriptions' => $subscriptions->where('status', self::SUBSCRIPTION_EXPIRED)->count(),
            'cancelled_subscriptions' => $subscriptions->where('status', self::SUBSCRIPTION_CANCELLED)->count(),
            'expiring_soon' => $subscriptions
                ->filter(function ($sub) use ($expiringThreshold) {
                    return $sub->status === self::SUBSCRIPTION_ACTIVE
                        && $sub->expires_at !== null
                        && $sub->expires_at <= $expiringThreshold;
                })->count(),
            'plan_distribution' => $subscriptions->groupBy('subscription_plan_id')
                ->map(function ($group) {
                    return [
                        'plan_name' => $group->first()->subscriptionPlan->name ?? 'Unknown',
                        'count' => $group->count(),
                        'active' => $group->where('status', self::SUBSCRIPTION_ACTIVE)->count(),
                    ];
                }),
            'churn_rate' => $this->calculateChurnRate(),
            'mrr' => $this->calculateMRR(), // Monthly Recurring Revenue
        ];
    }

    /**
     * Validate payment data for specific payment method
     */
    /**
     * Validate payment data — ZengaPay requires a phone number.
     * Accepts 'mobile_money', 'card', or 'zengapay' as method names
     * since ZengaPay is the sole gateway behind all of them.
     */
    protected function validatePaymentData(string $method, array $data): void
    {
        $accepted = ['zengapay', 'mobile_money', 'card'];

        if (! in_array($method, $accepted, true)) {
            throw new Exception("Unsupported payment method: {$method}. Accepted: ".implode(', ', $accepted));
        }

        if (empty($data['phone_number'])) {
            throw new Exception('Phone number is required for ZengaPay payments');
        }

        if (! preg_match('/^[0-9]{10,15}$/', preg_replace('/\D/', '', $data['phone_number']))) {
            throw new Exception('Invalid phone number format');
        }
    }

    /**
     * Create payment record
     */
    protected function createPayment(array $paymentData): Payment
    {
        // ZengaPay is the sole payment method
        $methodInfo = ['method' => 'zengapay', 'provider' => 'zengapay'];

        $data = [
            'user_id' => $paymentData['user_id'],
            'payment_reference' => $this->generatePaymentReference(),
            'transaction_id' => $this->generatePaymentReference(),
            'currency' => $paymentData['currency'],
            'payment_method' => $methodInfo['method'],
            'payment_provider' => $methodInfo['provider'],
            'phone_number' => $paymentData['phone_number'] ?? null,
            'description' => $paymentData['description'] ?? null,
            'metadata' => $paymentData['metadata'] ?? [],
        ];

        // Handle subscription plan polymorphic relationship
        if (isset($paymentData['subscription_plan_id'])) {
            $data['payable_type'] = 'App\Models\SubscriptionPlan';
            $data['payable_id'] = $paymentData['subscription_plan_id'];
            $data['payment_type'] = 'subscription';
        }

        $payment = new Payment($data);
        $payment->forceFill([
            'amount' => $paymentData['amount'],
            'status' => self::STATUS_PENDING,
        ]);
        $payment->save();

        return $payment;
    }

    /**
     * Process payment using appropriate payment method
     */
    /**
     * Process payment via ZengaPay
     */
    protected function processPayment(Payment $payment, array $paymentData): array
    {
        return $this->processZengaPayPayment($payment, $paymentData);
    }

    /**
     * Create subscription after successful payment
     */
    protected function createSubscription(User $user, SubscriptionPlan $plan, Payment $payment): UserSubscription
    {
        // Cancel ALL existing active subscriptions (handle concurrent requests)
        UserSubscription::where('user_id', $user->id)
            ->where('status', self::SUBSCRIPTION_ACTIVE)
            ->update([
                'status' => self::SUBSCRIPTION_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'upgraded',
            ]);

        return UserSubscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'payment_id' => $payment->id,
            'started_at' => now(),
            'expires_at' => now()->addDays($plan->duration_days),
            'status' => self::SUBSCRIPTION_ACTIVE,
            'payment_method' => $payment->payment_method,
            'amount_paid' => $payment->amount,
            'currency' => $payment->currency,
            'transaction_reference' => $payment->payment_reference,
            'auto_renew' => true,
        ]);
    }

    /**
     * Check if artist is eligible for payout
     */
    protected function isArtistEligibleForPayout(Artist $artist, float $amount): bool
    {
        $minimumPayout = config('payments.minimum_payout', 50000); // UGX

        return $artist->earnings_balance >= $amount &&
               $amount >= $minimumPayout &&
               ! empty($artist->payout_phone_number);
    }

    /**
     * Process method-specific refund
     */
    /**
     * Process refund via ZengaPay
     */
    protected function processMethodRefund(Payment $payment, float $amount): array
    {
        try {
            $zengapay = new ZengaPayGatewayAdapter;
            // ZengaPay refund is processed as a payout back to the customer
            $result = $zengapay->payout([
                'amount' => $amount,
                'phone' => $payment->phone_number,
                'reference' => 'REFUND-'.$payment->payment_reference,
                'description' => "Refund for payment #{$payment->id}",
            ]);

            return $result;
        } catch (Exception $e) {
            Log::error('ZengaPay refund failed', [
                'payment_id' => $payment->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Refund processing failed. Please try again.',
            ];
        }
    }

    /**
     * Generate unique payment reference
     */
    protected function generatePaymentReference(): string
    {
        return 'PAY_'.strtoupper(uniqid()).'_'.time();
    }

    /**
     * Get daily revenue breakdown
     */
    protected function getDailyRevenue(Collection $payments): array
    {
        return $payments->where('status', self::STATUS_COMPLETED)
            ->filter(function ($payment) {
                return $payment->completed_at !== null;
            })
            ->groupBy(function ($payment) {
                return $payment->completed_at->format('Y-m-d');
            })
            ->map(function ($group) {
                return $group->sum('amount');
            })
            ->toArray();
    }

    /**
     * Calculate subscription churn rate
     */
    protected function calculateChurnRate(): float
    {
        $totalSubscriptions = UserSubscription::count();

        if ($totalSubscriptions === 0) {
            return 0;
        }

        $cancelledThisMonth = UserSubscription::where('status', self::SUBSCRIPTION_CANCELLED)
            ->whereMonth('cancelled_at', now()->month)
            ->count();

        return round(($cancelledThisMonth / $totalSubscriptions) * 100, 2);
    }

    /**
     * Calculate Monthly Recurring Revenue
     */
    protected function calculateMRR(): float
    {
        return UserSubscription::where('status', self::SUBSCRIPTION_ACTIVE)
            ->with('subscriptionPlan')
            ->get()
            ->sum(function ($subscription) {
                return $subscription->subscriptionPlan->price_usd /
                       ($subscription->subscriptionPlan->duration_days / 30);
            });
    }

    /**
     * Process ZengaPay payment
     * ZengaPay is a payment aggregator supporting MTN, Airtel, Bank transfers, and Cards
     */
    protected function processZengaPayPayment(Payment $payment, array $data): array
    {
        try {
            $zengapay = new ZengaPayGatewayAdapter;

            // Prepare payment data
            $chargeData = [
                'amount' => $payment->amount,
                'phone' => $data['phone_number'] ?? $payment->phone_number,
                'reference' => $payment->payment_reference,
                'description' => $payment->description ?? "TesoTunes Payment #{$payment->id}",
            ];

            // Initiate collection
            $result = $zengapay->charge($chargeData);

            if ($result['success']) {
                // Update payment with ZengaPay transaction ID
                $payment->forceFill([
                    'status' => self::STATUS_PROCESSING,
                    'initiated_at' => now(),
                ])->save();

                $payment->update([
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                    'transaction_reference' => $result['reference'] ?? $payment->payment_reference,
                ]);

                Log::info('ZengaPay payment initiated', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]);

                return [
                    'success' => true,
                    'message' => $result['message'] ?? 'Payment request sent. Please approve on your phone.',
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'reference' => $result['reference'] ?? $payment->payment_reference,
                    'status' => 'pending',
                ];
            }

            Log::warning('ZengaPay payment failed', [
                'payment_id' => $payment->id,
                'error' => $result['message'] ?? 'Unknown error',
            ]);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Payment request failed',
            ];

        } catch (Exception $e) {
            Log::error('ZengaPay payment exception', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment service temporarily unavailable. Please try again.',
            ];
        }
    }

    /**
     * Process ZengaPay payout (disbursement)
     */
    public function processZengaPayPayout(Payout $payout): array
    {
        try {
            $zengapay = new ZengaPayGatewayAdapter;

            $payoutData = [
                'amount' => $payout->amount,
                'phone' => $payout->phone_number,
                'reference' => 'PAYOUT-'.$payout->id.'-'.time(),
                'description' => "TesoTunes Artist Payout #{$payout->id}",
            ];

            $result = $zengapay->payout($payoutData);

            if ($result['success']) {
                $payout->update([
                    'status' => 'processing',
                    'transaction_reference' => $result['transaction_id'] ?? null,
                    'provider_response' => $result,
                ]);

                Log::info('ZengaPay payout initiated', [
                    'payout_id' => $payout->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]);

                return [
                    'success' => true,
                    'message' => $result['message'] ?? 'Payout initiated successfully',
                    'transaction_id' => $result['transaction_id'] ?? null,
                ];
            }

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Payout request failed',
            ];

        } catch (Exception $e) {
            Log::error('ZengaPay payout exception', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payout service temporarily unavailable. Please try again.',
            ];
        }
    }

    /**
     * Check ZengaPay transaction status
     */
    public function checkZengaPayStatus(string $transactionId): array
    {
        try {
            $zengapay = new ZengaPayGatewayAdapter;

            return $zengapay->getTransactionStatus($transactionId);
        } catch (Exception $e) {
            Log::error('ZengaPay status check failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unable to check transaction status',
            ];
        }
    }

    /**
     * Get ZengaPay account balance
     */
    public function getZengaPayBalance(): array
    {
        try {
            $zengapay = new ZengaPayGatewayAdapter;

            return $zengapay->getBalance();
        } catch (Exception $e) {
            Log::error('ZengaPay balance check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Unable to retrieve account balance',
            ];
        }
    }

    /**
     * Notify user of refund
     */
    protected function notifyUserOfRefund(Payment $payment, float $amount, string $reason): void
    {
        $this->createAppNotification(
            $payment->user,
            'payment_refunded',
            'payments',
            'Payment Refunded',
            "Your payment of {$payment->currency} {$amount} has been refunded. Reason: {$reason}",
            [
                'payment_id' => $payment->id,
                'refund_amount' => $amount,
                'currency' => $payment->currency,
                'reason' => $reason,
                'transaction_reference' => $payment->transaction_reference,
            ],
            Payment::class,
            $payment->id,
            'normal'
        );
    }

    /**
     * Notify user of subscription cancellation
     */
    protected function notifyUserOfCancellation(UserSubscription $subscription, string $reason): void
    {
        $this->createAppNotification(
            $subscription->user,
            'subscription_cancelled',
            'subscription',
            'Subscription Cancelled',
            "Your subscription has been cancelled. Reason: {$reason}",
            [
                'subscription_id' => $subscription->id,
                'reason' => $reason,
                'expires_at' => $subscription->expires_at?->toIso8601String(),
                'plan_id' => $subscription->subscription_plan_id,
            ],
            UserSubscription::class,
            $subscription->id,
            'high'
        );
    }

    /**
     * Notify user of subscription extension
     */
    protected function notifyUserOfExtension(UserSubscription $subscription, int $days, string $reason): void
    {
        $this->createAppNotification(
            $subscription->user,
            'subscription_extended',
            'subscription',
            'Subscription Extended',
            "Your subscription has been extended by {$days} days. New expiry: {$subscription->expires_at->format('Y-m-d')}. Reason: {$reason}",
            [
                'subscription_id' => $subscription->id,
                'days' => $days,
                'reason' => $reason,
                'expires_at' => $subscription->expires_at?->toIso8601String(),
                'plan_id' => $subscription->subscription_plan_id,
            ],
            UserSubscription::class,
            $subscription->id
        );
    }

    protected function createAppNotification(
        ?User $user,
        string $type,
        string $category,
        string $title,
        string $message,
        array $data = [],
        ?string $notifiableType = null,
        ?int $notifiableId = null,
        string $priority = 'normal'
    ): void {
        if (! $user) {
            return;
        }

        AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'priority' => $priority,
            'data' => $data,
        ]);
    }
}
