<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * GET /api/admin/payment-analytics
     */
    public function analytics(Request $request): JsonResponse
    {
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
     */
    public function refund(Request $request, $payment): JsonResponse
    {
        $payment = Payment::where('id', $payment)
            ->orWhere('uuid', $payment)
            ->firstOrFail();

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

        \Illuminate\Support\Facades\Log::info("Payment webhook received from {$provider}", [
            'payload' => $payload,
        ]);

        // Find the payment by transaction reference
        $transactionId = $payload['transactionId'] ?? $payload['transaction_id'] ?? $payload['reference'] ?? null;

        if (! $transactionId) {
            return response()->json(['message' => 'Missing transaction reference.'], 400);
        }

        $payment = Payment::where('transaction_reference', $transactionId)
            ->orWhere('provider_transaction_id', $transactionId)
            ->first();

        if (! $payment) {
            \Illuminate\Support\Facades\Log::warning("Payment not found for webhook transaction: {$transactionId}");

            return response()->json(['message' => 'Payment not found.'], 404);
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

        return response()->json(['message' => 'Webhook processed.']);
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
     * GET /api/payments/history — get authenticated user's payment history
     */
    public function history(Request $request): JsonResponse
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json($payments);
    }
}
