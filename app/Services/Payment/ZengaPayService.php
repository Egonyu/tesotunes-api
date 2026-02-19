<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * ZengaPay Service — Higher-level payment service wrapping the gateway adapter
 *
 * Registered as a singleton in AppServiceProvider.
 * Provides a clean API for controllers and jobs to interact with ZengaPay.
 */
class ZengaPayService
{
    protected ZengaPayGatewayAdapter $gateway;

    public function __construct()
    {
        $this->gateway = new ZengaPayGatewayAdapter;
    }

    /**
     * Initiate a collection (request money from a phone number)
     */
    public function collect(float $amount, string $phone, string $reference, string $description = ''): array
    {
        return $this->gateway->charge([
            'amount' => $amount,
            'phone' => $phone,
            'reference' => $reference,
            'description' => $description ?: 'TesoTunes Payment',
        ]);
    }

    /**
     * Initiate a disbursement (send money to a phone number)
     */
    public function disburse(float $amount, string $phone, string $reference, string $description = ''): array
    {
        return $this->gateway->payout([
            'amount' => $amount,
            'phone' => $phone,
            'reference' => $reference,
            'description' => $description ?: 'TesoTunes Payout',
        ]);
    }

    /**
     * Check the status of a ZengaPay transaction
     */
    public function checkStatus(string $transactionId): array
    {
        return $this->gateway->getTransactionStatus($transactionId);
    }

    /**
     * Get the ZengaPay account balance
     */
    public function getBalance(): array
    {
        return $this->gateway->getBalance();
    }

    /**
     * Process a payment model through ZengaPay
     */
    public function processPayment(Payment $payment, array $data = []): array
    {
        try {
            $result = $this->collect(
                $payment->amount,
                $data['phone_number'] ?? $payment->phone_number,
                $payment->payment_reference,
                $payment->description ?? "TesoTunes Payment #{$payment->id}"
            );

            if ($result['success']) {
                $payment->forceFill([
                    'status' => 'processing',
                    'initiated_at' => now(),
                ])->save();

                $payment->update([
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                    'transaction_reference' => $result['reference'] ?? $payment->payment_reference,
                ]);

                Log::info('ZengaPay payment initiated via service', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]);
            }

            return $result;
        } catch (Exception $e) {
            Log::error('ZengaPayService::processPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment service temporarily unavailable.',
            ];
        }
    }

    /**
     * Verify a webhook signature from ZengaPay
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('services.zengapay.webhook_secret');

        if (empty($secret)) {
            Log::warning('ZengaPay webhook secret is not configured — skipping signature verification');

            return true;
        }

        $computed = hash_hmac('sha256', $payload, $secret);

        return hash_equals($computed, $signature);
    }

    /**
     * Handle a webhook callback from ZengaPay
     */
    public function handleWebhook(array $payload): array
    {
        $transactionId = $payload['transactionId'] ?? $payload['transaction_id'] ?? null;
        $externalRef = $payload['externalReference'] ?? $payload['external_reference'] ?? null;
        $status = strtolower($payload['transactionStatus'] ?? $payload['status'] ?? '');

        if (! $transactionId && ! $externalRef) {
            return ['success' => false, 'message' => 'Missing transaction identifier'];
        }

        $payment = Payment::where('provider_transaction_id', $transactionId)
            ->orWhere('transaction_reference', $transactionId)
            ->orWhere('payment_reference', $externalRef)
            ->first();

        if (! $payment) {
            Log::warning('ZengaPay webhook: payment not found', [
                'transaction_id' => $transactionId,
                'external_reference' => $externalRef,
            ]);

            return ['success' => false, 'message' => 'Payment not found'];
        }

        // Don't re-process already completed or failed payments
        if (in_array($payment->status, ['completed', 'refunded'])) {
            return ['success' => true, 'message' => 'Payment already finalized'];
        }

        match ($status) {
            'succeeded', 'successful', 'completed', 'success' => $payment->markAsCompleted([
                'external_transaction_id' => $transactionId,
                'payment_data' => ['webhook_payload' => $payload],
            ]),
            'failed', 'failure', 'declined', 'rejected' => $payment->markAsFailed(
                $payload['reason'] ?? $payload['message'] ?? 'Payment failed',
                ['payment_data' => ['webhook_payload' => $payload]]
            ),
            'cancelled', 'expired' => $payment->markAsCancelled(),
            default => Log::info('ZengaPay webhook: unhandled status', [
                'status' => $status,
                'payment_id' => $payment->id,
            ]),
        };

        Log::info('ZengaPay webhook processed', [
            'payment_id' => $payment->id,
            'status' => $status,
            'transaction_id' => $transactionId,
        ]);

        return [
            'success' => true,
            'message' => 'Webhook processed',
            'payment_id' => $payment->id,
            'new_status' => $payment->fresh()->status,
        ];
    }
}
