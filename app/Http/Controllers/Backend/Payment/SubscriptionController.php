<?php

namespace App\Http\Controllers\Backend\Payment;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Models\Payment;
use App\Services\Payment\MobileMoneyService;
use App\Services\Payment\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    protected $mobileMoneyService;
    protected $stripeService;

    public function __construct(
        MobileMoneyService $mobileMoneyService,
        StripeService $stripeService
    ) {
        $this->mobileMoneyService = $mobileMoneyService;
        $this->stripeService = $stripeService;
    }

    public function plans(Request $request): JsonResponse
    {
        try {
            $plans = SubscriptionPlan::where('is_active', true)
                ->where('region', $request->get('region', 'UG'))
                ->orderBy('price')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $plans
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch subscription plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function subscribe(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'plan_id' => 'required|exists:subscription_plans,id',
                'payment_method' => 'required|in:mtn_mobile_money,airtel_money,stripe,bank_transfer',
                'phone_number' => 'required_if:payment_method,mtn_mobile_money,airtel_money|regex:/^[0-9]{10,15}$/',
                'currency' => 'required|in:UGX,USD,EUR,GBP',
                'auto_renew' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $plan = SubscriptionPlan::findOrFail($request->plan_id);
            $paymentMethod = $request->payment_method;

            // Check if user already has active subscription
            $activeSubscription = $user->subscription()->where('status', 'active')->first();
            if ($activeSubscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has an active subscription'
                ], 409);
            }

            // Calculate price based on currency and region
            $price = $this->calculatePrice($plan, $request->currency, $user->country);

            // Create payment record
            $payment = Payment::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'payment_reference' => 'PAY_' . Str::upper(Str::random(12)),
                'amount' => $price,
                'currency' => $request->currency,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
                'metadata' => [
                    'phone_number' => $request->phone_number,
                    'auto_renew' => $request->boolean('auto_renew', true),
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ]
            ]);

            // Process payment based on method
            $paymentResult = match($paymentMethod) {
                'mtn_mobile_money' => $this->mobileMoneyService->initiateMTNPayment($payment),
                'airtel_money' => $this->mobileMoneyService->initiateAirtelPayment($payment),
                'stripe' => $this->stripeService->createPaymentIntent($payment),
                'bank_transfer' => $this->createBankTransferInstructions($payment),
                default => throw new \Exception('Unsupported payment method')
            };

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'data' => [
                    'payment_reference' => $payment->payment_reference,
                    'amount' => $price,
                    'currency' => $request->currency,
                    'payment_method' => $paymentMethod,
                    'payment_data' => $paymentResult
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function confirmPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_reference' => 'required|string|exists:payments,payment_reference',
                'transaction_id' => 'nullable|string',
                'payment_data' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $payment = Payment::where('payment_reference', $request->payment_reference)->firstOrFail();
            $user = $payment->user;
            $plan = $payment->subscriptionPlan;

            // Verify payment status with provider
            $verified = match($payment->payment_method) {
                'mtn_mobile_money' => $this->mobileMoneyService->verifyMTNPayment($payment, $request->transaction_id),
                'airtel_money' => $this->mobileMoneyService->verifyAirtelPayment($payment, $request->transaction_id),
                'stripe' => $this->stripeService->verifyPayment($payment, $request->payment_data),
                'bank_transfer' => $this->verifyBankTransfer($payment, $request->transaction_id),
                default => false
            };

            if (!$verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment verification failed'
                ], 400);
            }

            // Update payment status
            $payment->update([
                'status' => 'completed',
                'transaction_id' => $request->transaction_id,
                'completed_at' => now(),
            ]);

            // Create or update subscription
            $subscription = UserSubscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'payment_id' => $payment->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays($plan->duration_days),
                'status' => 'active',
                'auto_renew' => $payment->metadata['auto_renew'] ?? true,
            ]);

            // Update user subscription status
            $user->update(['subscription_status' => 'active']);

            // Create success notification
            $user->notifications()->create([
                'type' => 'subscription_activated',
                'title' => 'Subscription Activated',
                'message' => "Your {$plan->name} subscription is now active!",
                'data' => [
                    'plan_name' => $plan->name,
                    'expires_at' => $subscription->ends_at,
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed and subscription activated',
                'data' => [
                    'subscription' => $subscription,
                    'plan' => $plan,
                    'expires_at' => $subscription->ends_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function current(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $subscription = $user->subscription()->with('subscriptionPlan')->first();

            if (!$subscription) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_subscription' => false,
                        'subscription' => null
                    ]
                ]);
            }

            // Add computed attributes
            $subscription->days_remaining = $subscription->ends_at->diffInDays(now());
            $subscription->is_expiring_soon = $subscription->ends_at->lt(now()->addDays(7));

            return response()->json([
                'success' => true,
                'data' => [
                    'has_subscription' => true,
                    'subscription' => $subscription
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch current subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $subscription = $user->subscription()->where('status', 'active')->first();

            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription found'
                ], 404);
            }

            $subscription->update([
                'status' => 'cancelled',
                'auto_renew' => false,
                'cancelled_at' => now(),
            ]);

            // Create notification
            $user->notifications()->create([
                'type' => 'subscription_cancelled',
                'title' => 'Subscription Cancelled',
                'message' => 'Your subscription has been cancelled. You can still use premium features until ' . $subscription->ends_at->format('M j, Y'),
                'data' => [
                    'expires_at' => $subscription->ends_at,
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully',
                'data' => [
                    'expires_at' => $subscription->ends_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function invoices(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $payments = Payment::where('user_id', $user->id)
                ->where('status', 'completed')
                ->with('subscriptionPlan')
                ->orderBy('completed_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $payments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch invoices',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function calculatePrice(SubscriptionPlan $plan, string $currency, string $country): float
    {
        // Base price in USD
        $basePrice = $plan->price_usd;

        // African market pricing adjustments
        $countryMultipliers = [
            'UG' => 0.6,  // Uganda - 40% discount
            'KE' => 0.7,  // Kenya - 30% discount
            'TZ' => 0.6,  // Tanzania - 40% discount
            'RW' => 0.7,  // Rwanda - 30% discount
            'default' => 1.0
        ];

        $multiplier = $countryMultipliers[$country] ?? $countryMultipliers['default'];
        $adjustedPrice = $basePrice * $multiplier;

        // Convert to requested currency
        return match($currency) {
            'UGX' => $adjustedPrice * 3700, // 1 USD = 3700 UGX (approximate)
            'KES' => $adjustedPrice * 150,  // 1 USD = 150 KES (approximate)
            'TZS' => $adjustedPrice * 2300, // 1 USD = 2300 TZS (approximate)
            'USD' => $adjustedPrice,
            'EUR' => $adjustedPrice * 0.85,
            'GBP' => $adjustedPrice * 0.75,
            default => $adjustedPrice
        };
    }

    private function createBankTransferInstructions(Payment $payment): array
    {
        return [
            'instructions' => 'Please transfer the exact amount to the following bank account:',
            'bank_details' => [
                'bank_name' => 'Stanbic Bank Uganda',
                'account_name' => 'Your Music Platform Ltd',
                'account_number' => '****1234567890',
                'branch_code' => '9540',
                'swift_code' => 'SBICUGKX',
                'reference' => $payment->payment_reference,
            ],
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'deadline' => now()->addHours(24)->toISOString(),
        ];
    }

    private function verifyBankTransfer(Payment $payment, ?string $transactionId): bool
    {
        // In a real implementation, this would check with the bank's API
        // For now, return false to require manual verification
        return false;
    }
}