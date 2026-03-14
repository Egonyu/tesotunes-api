<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles mobile money payment webhooks from direct provider integrations.
 *
 * For ZengaPay-mediated mobile money, use the /webhooks/zengapay endpoint instead.
 * This controller handles callbacks from direct MTN MoMo or Airtel Money APIs.
 */
class MobileMoneyWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $provider = $request->header('X-Provider', $payload['provider'] ?? 'unknown');

        Log::info('Mobile money webhook received', [
            'provider' => $provider,
            'ip' => $request->ip(),
            'payload_keys' => array_keys($payload),
        ]);

        // Verify signature
        if (! $this->verifySignature($request, $provider)) {
            Log::warning('Mobile money webhook: invalid signature', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        // Extract transaction reference (providers use different field names)
        $transactionId = $payload['transactionId']
            ?? $payload['transaction_id']
            ?? $payload['financialTransactionId']
            ?? $payload['externalId']
            ?? $payload['reference']
            ?? null;

        if (! $transactionId) {
            return response()->json(['message' => 'Missing transaction reference.'], 400);
        }

        $payment = Payment::where('transaction_reference', $transactionId)
            ->orWhere('provider_transaction_id', $transactionId)
            ->first();

        if (! $payment) {
            Log::warning('Mobile money webhook: payment not found', [
                'transaction_id' => $transactionId,
                'provider' => $provider,
            ]);

            return response()->json(['message' => 'Payment not found.'], 404);
        }

        // Idempotency: skip finalized payments
        if (in_array($payment->status, ['completed', 'refunded', 'cancelled'])) {
            return response()->json([
                'message' => 'Payment already processed.',
                'status' => $payment->status,
            ]);
        }

        $status = strtolower($payload['status'] ?? $payload['transactionStatus'] ?? '');

        if (in_array($status, ['completed', 'successful', 'success', 'succeeded'])) {
            $payment->markAsCompleted([
                'external_transaction_id' => $transactionId,
                'provider_reference' => $payload['reference'] ?? $payload['externalId'] ?? $payment->payment_reference,
                'payment_data' => ['webhook_payload' => $payload],
            ]);

            Log::info('Mobile money payment completed', [
                'payment_id' => $payment->id,
                'provider' => $provider,
            ]);
        } elseif (in_array($status, ['failed', 'failure', 'declined', 'rejected'])) {
            $payment->markAsFailed(
                $payload['reason'] ?? $payload['message'] ?? 'Payment failed',
                ['payment_data' => ['webhook_payload' => $payload]]
            );

            Log::warning('Mobile money payment failed', [
                'payment_id' => $payment->id,
                'provider' => $provider,
                'reason' => $payload['reason'] ?? $payload['message'] ?? 'unknown',
            ]);
        } elseif (in_array($status, ['cancelled', 'expired', 'timeout'])) {
            $payment->markAsCancelled();
        }

        return response()->json([
            'message' => 'Webhook processed.',
            'payment_id' => $payment->id,
            'new_status' => $payment->fresh()->status,
        ]);
    }

    /**
     * Verify webhook signature from mobile money provider.
     */
    protected function verifySignature(Request $request, string $provider): bool
    {
        $signature = $request->header('X-Signature')
            ?? $request->header('X-Callback-Signature')
            ?? '';

        $secret = match ($provider) {
            'mtn', 'mtn_momo' => config('services.mtn.webhook_secret'),
            'airtel', 'airtel_money' => config('services.airtel.webhook_secret'),
            default => config('services.payment.webhook_secret'),
        };

        if (empty($secret)) {
            if (app()->environment('local', 'testing')) {
                Log::warning("Mobile money webhook: no secret configured for {$provider} — skipping in dev");

                return true;
            }

            return false;
        }

        $computed = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($computed, $signature);
    }
}
