<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Services\Payment\ZengaPayService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

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
     * GET /api/admin/payment-analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        $filters = $request->only(['start_date', 'end_date', 'status']);

        $analytics = $this->paymentService->getPaymentAnalytics($filters);

        return response()->json([
            'data' => $analytics,
        ]);
    }

    /**
     * POST /api/payments/subscription — process subscription payment
     */
    public function processSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,id',
            'payment_method' => 'required|string|in:mobile_money,card,credits',
            'phone_number' => 'required_if:payment_method,mobile_money|string',
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);
        $user = $request->user();

        $result = $this->paymentService->processSubscriptionPayment(
            $user,
            $plan,
            $validated['payment_method'],
            $request->only(['phone_number', 'email', 'card_token'])
        );

        $statusCode = ($result['success'] ?? false) ? 201 : 422;

        return response()->json([
            'data' => $result,
        ], $statusCode);
    }

    /**
     * POST /api/payments/{payment}/refund — refund a payment
     * HIGH-7 fix: Added ownership check — only payment owner or admin can refund
     */
    public function refund(Request $request, $payment): JsonResponse
    {
        $payment = Payment::where('id', $payment)
            ->orWhere('uuid', $payment)
            ->firstOrFail();

        // Ownership check: only the payment owner or admin can issue a refund
        $user = $request->user();
        if ($payment->user_id !== $user->id && ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to refund this payment.',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        $result = $this->paymentService->processRefund(
            $payment,
            $validated['amount'] ?? null,
            $validated['reason'] ?? ''
        );

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * POST /api/payments/artist-payout — process artist payout
     */
    public function artistPayout(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        $validated = $request->validate([
            'artist_id' => 'required|integer|exists:artists,id',
            'amount' => 'required|numeric|min:1',
            'method' => 'nullable|string|in:mobile_money,bank_transfer',
        ]);

        $artist = \App\Models\Artist::findOrFail($validated['artist_id']);

        $result = $this->paymentService->processArtistPayout(
            $artist,
            $validated['amount'],
            $validated['method'] ?? 'mobile_money'
        );

        $statusCode = ($result['success'] ?? false) ? 201 : 422;

        return response()->json([
            'data' => $result,
        ], $statusCode);
    }

    /**
     * POST /api/payments/webhook — handle payment provider webhook
     */
    public function webhook(Request $request, ?string $provider = null): JsonResponse
    {
        $provider = $provider ?? 'zengapay';
        $payload = $request->all();

        Log::info("Payment webhook received from {$provider}", [
            'payload' => $payload,
            'ip' => $request->ip(),
        ]);

        // Verify webhook signature
        if (! $this->verifyWebhookSignature($request, $provider)) {
            Log::warning("Payment webhook: invalid signature from {$provider}", [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // Find the payment by transaction reference
        $transactionId = $payload['transactionId'] ?? $payload['transaction_id'] ?? $payload['reference'] ?? null;

        if (! $transactionId) {
            return response()->json(['message' => 'Missing transaction reference.'], 400);
        }

        $payment = Payment::where('transaction_reference', $transactionId)
            ->orWhere('provider_transaction_id', $transactionId)
            ->first();

        if (! $payment) {
            Log::warning("Payment not found for webhook transaction: {$transactionId}");

            return response()->json(['message' => 'Payment not found.'], 404);
        }

        // Idempotency: don't re-process finalized payments
        if (in_array($payment->status, ['completed', 'refunded', 'cancelled'])) {
            Log::info('Payment webhook: already finalized', [
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);

            return response()->json([
                'message' => 'Payment already processed.',
                'status' => $payment->status,
            ]);
        }

        $status = strtolower($payload['status'] ?? '');

        if (in_array($status, ['completed', 'successful', 'success'])) {
            $payment->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();
        } elseif (in_array($status, ['failed', 'failure', 'declined'])) {
            $payment->forceFill([
                'status' => 'failed',
                'failure_reason' => $payload['reason'] ?? $payload['message'] ?? 'Payment failed',
            ])->save();
        }

        return response()->json([
            'message' => 'Webhook processed.',
            'payment_id' => $payment->id,
            'new_status' => $payment->fresh()->status,
        ]);
    }

    /**
     * Verify webhook signature from payment provider.
     */
    protected function verifyWebhookSignature(Request $request, string $provider): bool
    {
        $signature = $request->header('X-Signature')
            ?? $request->header('X-Webhook-Signature')
            ?? $request->header('X-ZengaPay-Signature')
            ?? '';

        $secret = match ($provider) {
            'zengapay' => config('services.zengapay.webhook_secret'),
            'airtel' => config('services.airtel.webhook_secret'),
            default => config('services.payment.webhook_secret'),
        };

        if (empty($secret)) {
            if (app()->environment('local', 'testing')) {
                Log::warning("Webhook signature verification skipped in {$provider} — no secret configured");

                return true;
            }

            Log::error("Webhook secret not configured for provider: {$provider}");

            return false;
        }

        $computed = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($computed, $signature);
    }

    /**
     * GET /api/payments/status/{transactionId} — check ZengaPay transaction status
     */
    public function checkStatus(string $transactionId): JsonResponse
    {
        $result = $this->paymentService->checkZengaPayStatus($transactionId);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * GET /api/payments/zengapay/balance — get ZengaPay account balance (admin only)
     */
    public function zengapayBalance(): JsonResponse
    {
        $result = $this->paymentService->getZengaPayBalance();

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * GET /api/payments/methods — lightweight payment options for the client.
     */
    public function methods(): JsonResponse
    {
        return response()->json([
            'data' => [
                'mobile_money' => [
                    [
                        'id' => 'mtn_momo',
                        'name' => 'ZengaPay • MTN Mobile Money',
                        'icon' => 'smartphone',
                        'min_amount' => 1000,
                        'max_amount' => 5000000,
                        'currency' => 'UGX',
                        'enabled' => true,
                    ],
                    [
                        'id' => 'airtel_money',
                        'name' => 'ZengaPay • Airtel Money',
                        'icon' => 'smartphone',
                        'min_amount' => 1000,
                        'max_amount' => 5000000,
                        'currency' => 'UGX',
                        'enabled' => true,
                    ],
                ],
                'other' => [
                    [
                        'id' => 'wallet',
                        'name' => 'Wallet',
                        'icon' => 'wallet',
                        'min_amount' => 1,
                        'max_amount' => 5000000,
                        'currency' => 'UGX',
                        'enabled' => true,
                    ],
                ],
            ],
        ]);
    }

    /**
     * POST /api/payments/mobile-money/validate-phone
     */
    public function validatePhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|min:9',
        ]);

        $normalized = preg_replace('/\D+/', '', $validated['phone']) ?? '';
        if (str_starts_with($normalized, '0')) {
            $normalized = '256'.substr($normalized, 1);
        }
        if (! str_starts_with($normalized, '256')) {
            $normalized = '256'.$normalized;
        }

        $provider = 'unknown';
        if (preg_match('/^256(77|78|76|39)/', $normalized) === 1) {
            $provider = 'mtn_momo';
        } elseif (preg_match('/^256(70|75|74)/', $normalized) === 1) {
            $provider = 'airtel_money';
        }

        return response()->json([
            'data' => [
                'valid' => strlen($normalized) === 12 && $provider !== 'unknown',
                'phone' => $normalized,
                'provider' => $provider,
                'formatted' => '+'.substr($normalized, 0, 3).' '.substr($normalized, 3, 3).' '.substr($normalized, 6),
            ],
        ]);
    }

    /**
     * POST /api/payments/mobile-money/initiate — initiate mobile money deposit (wallet topup)
     */
    public function initiateMobileMoneyDeposit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1000|max:5000000',
            'phone' => 'required|string|min:9',
            'provider' => 'nullable|string|in:mtn_momo,airtel_money',
            'purpose' => 'nullable|string|in:wallet_topup,credits_purchase',
        ]);

        $user = $request->user();
        $amount = (float) $validated['amount'];
        $phone = $validated['phone'];
        $purpose = $validated['purpose'] ?? 'wallet_topup';
        $reference = 'TT-'.strtoupper(Str::random(12));

        try {
            // Create payment record
            $payment = new Payment;
            $payment->forceFill([
                'amount' => $amount,
                'status' => Payment::STATUS_PENDING,
            ]);
            $payment->fill([
                'user_id' => $user->id,
                'payment_type' => $purpose,
                'payment_method' => Payment::METHOD_ZENGAPAY,
                'provider' => Payment::PROVIDER_ZENGAPAY,
                'payment_provider' => Payment::PROVIDER_ZENGAPAY,
                'phone_number' => $phone,
                'currency' => 'UGX',
                'payment_reference' => $reference,
                'transaction_reference' => $reference,
                'description' => 'Wallet top-up of UGX '.number_format($amount),
            ]);
            $payment->save();

            // In dev/sandbox mode, simulate a successful payment
            $zengaPayConfig = config('services.zengapay');
            if (empty($zengaPayConfig['api_key']) || app()->environment('local', 'testing')) {
                $payment->update([
                    'status' => Payment::STATUS_PROCESSING,
                    'provider_transaction_id' => 'DEV-'.strtoupper(\Illuminate\Support\Str::random(16)),
                ]);

                // In local dev, auto-complete the payment after a short delay
                if (app()->environment('local', 'testing')) {
                    $payment->markAsCompleted([
                        'external_transaction_id' => $payment->provider_transaction_id,
                        'provider_reference' => $reference,
                    ]);
                }

                $payment->refresh();

                return response()->json([
                    'data' => [
                        'transaction_ref' => $reference,
                        'status' => $payment->status,
                        'message' => app()->environment('local', 'testing')
                            ? 'DEV MODE: Payment auto-completed. Balance updated.'
                            : 'Payment initiated successfully.',
                    ],
                ], 200);
            }

            // Call ZengaPay to initiate collection
            $zengaPay = app(ZengaPayService::class);
            $result = $zengaPay->collect(
                $amount,
                $phone,
                $reference,
                'TesoTunes Wallet Top-Up - UGX '.number_format($amount)
            );

            if ($result['success']) {
                $payment->forceFill([
                    'status' => Payment::STATUS_PROCESSING,
                    'initiated_at' => now(),
                ]);
                $payment->update([
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                ]);

                Log::info('Mobile money deposit initiated', [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'reference' => $reference,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]);

                return response()->json([
                    'data' => [
                        'success' => true,
                        'transaction_ref' => $reference,
                        'message' => $result['message'] ?? 'Please approve the payment on your phone.',
                    ],
                ], 201);
            }

            // Collection request failed
            $payment->forceFill([
                'status' => Payment::STATUS_FAILED,
                'failed_at' => now(),
            ]);
            $payment->update([
                'failure_reason' => $result['message'] ?? 'Failed to initiate payment',
            ]);

            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to initiate payment. Please try again.',
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Mobile money deposit failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'Payment service temporarily unavailable. Please try again later.',
                ],
            ], 500);
        }
    }

    /**
     * GET /api/payments/mobile-money/status/{reference} — check payment status
     */
    public function mobileMoneyStatus(Request $request, string $reference): JsonResponse
    {
        $payment = Payment::where('transaction_reference', $reference)
            ->orWhere('payment_reference', $reference)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $payment) {
            return response()->json([
                'data' => [
                    'status' => 'not_found',
                    'message' => 'Payment not found.',
                ],
            ], 404);
        }

        // If still processing, poll ZengaPay for latest status
        if ($payment->status === Payment::STATUS_PROCESSING && $payment->provider_transaction_id) {
            try {
                $zengaPay = app(ZengaPayService::class);
                $statusResult = $zengaPay->checkStatus($payment->provider_transaction_id);

                if (! empty($statusResult['status'])) {
                    $zgStatus = strtolower($statusResult['status']);

                    if (in_array($zgStatus, ['succeeded', 'successful', 'completed', 'success'])) {
                        $payment->markAsCompleted([
                            'external_transaction_id' => $payment->provider_transaction_id,
                            'provider_reference' => $reference,
                            'payment_data' => ['status_polled_at' => now()->toIso8601String()],
                        ]);
                    } elseif (in_array($zgStatus, ['failed', 'failure', 'declined', 'rejected'])) {
                        $payment->markAsFailed(
                            $statusResult['reason'] ?? 'Payment was declined',
                            ['payment_data' => ['status_polled_at' => now()->toIso8601String()]]
                        );
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to poll ZengaPay status', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'status' => $payment->status,
                'amount' => $payment->amount,
                'reference' => $reference,
                'message' => $payment->status === 'completed'
                    ? 'Payment successful!'
                    : ($payment->status === 'failed'
                        ? ($payment->failure_reason ?? 'Payment failed')
                        : 'Payment is being processed...'),
            ],
        ]);
    }

    /**
     * GET /api/payments/wallet — get user wallet info
     */
    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'ugx_balance' => (float) ($user->ugx_balance ?? 0),
                'credits_balance' => (float) $user->credit_balance,
                'currency' => 'UGX',
            ],
        ]);
    }

    /**
     * GET /api/payments/wallet/transactions — get wallet transaction history
     */
    public function walletTransactions(Request $request): JsonResponse
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->whereIn('payment_type', ['wallet_topup', 'credits_purchase', 'credits_sale', 'withdrawal'])
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request));

        return response()->json($payments);
    }

    /**
     * POST /api/payments/wallet/withdraw — request withdrawal
     */
    public function withdraw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1000',
            'phone' => 'required|string|min:9',
            'provider' => 'nullable|string|in:mtn_momo,airtel_money,zengapay',
        ]);

        $user = $request->user();
        $amount = (float) $validated['amount'];

        if (($user->ugx_balance ?? 0) < $amount) {
            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'Insufficient wallet balance.',
                ],
            ], 422);
        }

        $reference = 'TT-W-'.strtoupper(Str::random(10));

        try {
            $payment = new Payment;
            $payment->forceFill([
                'amount' => $amount,
                'status' => Payment::STATUS_PENDING,
            ]);
            $payment->fill([
                'user_id' => $user->id,
                'payment_type' => 'withdrawal',
                'payment_method' => Payment::METHOD_ZENGAPAY,
                'provider' => Payment::PROVIDER_ZENGAPAY,
                'phone_number' => $validated['phone'],
                'currency' => 'UGX',
                'payment_reference' => $reference,
                'transaction_reference' => $reference,
                'description' => 'Withdrawal of UGX '.number_format($amount),
            ]);
            $payment->save();

            // Deduct from wallet immediately
            $user->decrement('ugx_balance', $amount);

            $zengaPay = app(ZengaPayService::class);
            $result = $zengaPay->disburse(
                $amount,
                $validated['phone'],
                $reference,
                'TesoTunes Withdrawal - UGX '.number_format($amount)
            );

            if ($result['success']) {
                $payment->forceFill([
                    'status' => Payment::STATUS_PROCESSING,
                    'initiated_at' => now(),
                ]);
                $payment->update([
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                ]);

                return response()->json([
                    'data' => [
                        'success' => true,
                        'reference' => $reference,
                        'status' => 'processing',
                        'amount' => $amount,
                        'phone' => $validated['phone'],
                        'message' => 'Withdrawal is being processed.',
                    ],
                ], 201);
            }

            // Refund if disbursement failed
            $user->increment('ugx_balance', $amount);
            $payment->forceFill([
                'status' => Payment::STATUS_FAILED,
                'failed_at' => now(),
            ])->save();

            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => $result['message'] ?? 'Withdrawal failed. Amount has been refunded to your wallet.',
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Withdrawal failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'Withdrawal service temporarily unavailable.',
                ],
            ], 500);
        }
    }

    /**
     * GET /api/payments/history — get authenticated user's payment history
     */
    public function history(Request $request): JsonResponse
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($this->getPerPage($request, 15));

        return response()->json($payments);
    }
}
