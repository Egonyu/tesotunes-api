<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * ZengaPay Service — Higher-level payment service wrapping the gateway adapter.
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
     * Initiate a collection (request money from a phone number).
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
     * Initiate a disbursement (send money to a phone number).
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
     * Check the status of a ZengaPay transaction.
     */
    public function checkStatus(string $transactionId): array
    {
        return $this->gateway->getTransactionStatus($transactionId);
    }

    /**
     * Get the ZengaPay account balance.
     */
    public function getBalance(): array
    {
        return $this->gateway->getBalance();
    }

    /**
     * Process a payment model through ZengaPay.
     */
    public function processPayment(Payment $payment, array $data = []): array
    {
        try {
            $result = $this->collect(
                (float) $payment->amount,
                $data['phone_number'] ?? $payment->phone_number,
                $payment->payment_reference,
                $payment->description ?? "TesoTunes Payment #{$payment->id}"
            );

            if ($result['success']) {
                $payment->forceFill([
                    'status' => Payment::STATUS_PROCESSING,
                    'initiated_at' => now(),
                ])->save();

                $payment->update([
                    'provider_transaction_id' => $result['transaction_id'] ?? $payment->provider_transaction_id,
                    'provider_reference' => $result['reference'] ?? $payment->provider_reference,
                    'transaction_reference' => $result['reference'] ?? $payment->transaction_reference ?? $payment->payment_reference,
                    'provider_response' => $result['raw_response'] ?? $payment->provider_response,
                ]);

                $this->observability()->recordAudit($payment->fresh(), 'payment_initiated', [
                    'channel' => 'zengapay',
                    'provider_transaction_id' => $result['transaction_id'] ?? null,
                    'reference' => $result['reference'] ?? null,
                    'message' => $result['message'] ?? null,
                ]);

                if (blank($result['transaction_id'] ?? null)) {
                    $this->observability()->recordIssue(
                        $payment->fresh(),
                        PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
                        "Missing provider transaction reference for payment #{$payment->id}",
                        [
                            'description' => 'ZengaPay accepted the initiation request but did not return a provider transaction identifier.',
                            'severity' => 'high',
                            'money_deducted' => false,
                            'service_delivered' => false,
                            'metadata' => [
                                'result' => Arr::except($result, ['raw_response']),
                            ],
                        ]
                    );
                } else {
                    $this->observability()->resolveIssue(
                        $payment->fresh(),
                        PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
                        'Provider transaction identifier captured successfully.'
                    );
                }

                Log::info('ZengaPay payment initiated via service', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]);
            } else {
                $payment->markAsFailed($result['message'] ?? 'Payment initiation failed', [
                    'payment_data' => [
                        'initiation_failed_at' => now()->toIso8601String(),
                    ],
                ]);

                $this->observability()->recordAudit($payment->fresh(), 'payment_initiation_failed', [
                    'channel' => 'zengapay',
                    'message' => $result['message'] ?? 'Payment initiation failed',
                ]);

                $this->observability()->recordIssue(
                    $payment->fresh(),
                    PaymentIssue::TYPE_PROVIDER_ERROR,
                    "Provider initiation failed for payment #{$payment->id}",
                    [
                        'description' => $result['message'] ?? 'ZengaPay initiation failed.',
                        'severity' => 'high',
                        'money_deducted' => false,
                        'service_delivered' => false,
                    ]
                );
            }

            return $result;
        } catch (Exception $e) {
            Log::error('ZengaPayService::processPayment failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            $this->observability()->recordAudit($payment, 'payment_initiation_exception', [
                'channel' => 'zengapay',
                'message' => $e->getMessage(),
            ]);

            $this->observability()->recordIssue(
                $payment,
                PaymentIssue::TYPE_PROVIDER_ERROR,
                "Provider exception for payment #{$payment->id}",
                [
                    'description' => $e->getMessage(),
                    'severity' => 'critical',
                    'money_deducted' => false,
                    'service_delivered' => false,
                ]
            );

            return [
                'success' => false,
                'message' => 'Payment service temporarily unavailable.',
            ];
        }
    }

    /**
     * Verify a webhook signature from ZengaPay.
     */
    public function verifyWebhookSignature(string $payload, string $signature, array $parsedPayload = []): bool
    {
        $secret = config('services.zengapay.webhook_secret');

        if (empty($secret)) {
            if (app()->environment('local', 'testing')) {
                Log::warning('ZengaPay webhook secret is not configured — skipping signature verification in dev');

                return true;
            }

            Log::error('ZengaPay webhook secret is not configured in production — rejecting webhook');

            return false;
        }

        $normalizedSignature = $this->normalizeSignature($signature);

        if ($normalizedSignature === '') {
            return false;
        }

        foreach ($this->signaturePayloadCandidates($payload, $parsedPayload) as $candidate) {
            $hex = hash_hmac('sha256', $candidate, $secret);
            $base64 = base64_encode(hash_hmac('sha256', $candidate, $secret, true));

            if (hash_equals($hex, $normalizedSignature) || hash_equals($base64, $normalizedSignature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a webhook callback from ZengaPay.
     */
    public function handleWebhook(array $payload): array
    {
        $data = $this->extractWebhookData($payload);
        $transactionId = $data['transaction_id'];
        $externalRef = $data['external_reference'];
        $status = $data['status'];
        $amount = $data['amount'];

        if (! $transactionId && ! $externalRef) {
            return ['success' => false, 'message' => 'Missing transaction identifier'];
        }

        $payment = $this->locatePayment($transactionId, $externalRef);

        if (! $payment) {
            Log::warning('ZengaPay webhook: payment not found', [
                'transaction_id' => $transactionId,
                'external_reference' => $externalRef,
            ]);

            return ['success' => false, 'message' => 'Payment not found'];
        }

        $payment->loadMissing('issues');

        $this->observability()->recordAudit($payment, 'payment_webhook_received', [
            'channel' => 'zengapay',
            'transaction_id' => $transactionId,
            'external_reference' => $externalRef,
            'status' => $status,
        ]);

        if ($amount !== null && abs((float) $payment->amount - (float) $amount) > 0.01) {
            $this->observability()->recordIssue(
                $payment,
                PaymentIssue::TYPE_AMOUNT_MISMATCH,
                "Amount mismatch detected for payment #{$payment->id}",
                [
                    'description' => "Expected {$payment->amount} but webhook reported {$amount}.",
                    'severity' => 'critical',
                    'money_deducted' => true,
                    'service_delivered' => $payment->isCompleted(),
                    'provider_status' => $status,
                    'metadata' => [
                        'expected_amount' => (float) $payment->amount,
                        'provider_amount' => (float) $amount,
                    ],
                ]
            );
        } else {
            $this->observability()->resolveIssue(
                $payment,
                PaymentIssue::TYPE_AMOUNT_MISMATCH,
                'Webhook amount matches the expected payment amount.'
            );
        }

        // Don't re-process already finalized payments, but backfill identifiers if they were missing.
        if (in_array($payment->status, [Payment::STATUS_COMPLETED, Payment::STATUS_REFUNDED, Payment::STATUS_FAILED], true)) {
            if ($transactionId && blank($payment->provider_transaction_id)) {
                $payment->forceFill([
                    'provider_transaction_id' => $transactionId,
                    'provider_response' => $payload,
                ])->save();
            }

            return ['success' => true, 'message' => 'Payment already finalized'];
        }

        $this->stampWebhookMetadata($payment, $payload, $transactionId, $externalRef, $status);

        match ($status) {
            'completed' => $payment->markAsCompleted([
                'external_transaction_id' => $transactionId,
                'provider_reference' => $externalRef ?? $payment->payment_reference,
                'payment_data' => [
                    'webhook_payload' => $payload,
                    'webhook_received_at' => now()->toIso8601String(),
                    'webhook_status' => $status,
                ],
            ]),
            'failed' => $payment->markAsFailed(
                $data['reason'] ?? 'Payment failed',
                ['payment_data' => ['webhook_payload' => $payload, 'webhook_received_at' => now()->toIso8601String()]]
            ),
            'cancelled' => $payment->markAsCancelled(),
            'refunded' => $payment->markAsRefunded(),
            default => $payment->markAsProcessing(),
        };

        $this->observability()->resolveIssue(
            $payment->fresh(),
            PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE,
            'Received a valid webhook for this payment.'
        );

        $this->observability()->resolveIssue(
            $payment->fresh(),
            PaymentIssue::TYPE_WEBHOOK_MISSING,
            'Webhook arrived and the payment lifecycle resumed.'
        );

        if ($transactionId) {
            $this->observability()->resolveIssue(
                $payment->fresh(),
                PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
                'Provider transaction identifier backfilled from webhook.'
            );
        }

        $this->observability()->recordAudit($payment->fresh(), 'payment_webhook_processed', [
            'channel' => 'zengapay',
            'transaction_id' => $transactionId,
            'external_reference' => $externalRef,
            'status' => $status,
            'new_status' => $payment->fresh()->status,
        ]);

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

    public function recordWebhookSignatureFailure(array $payload, ?string $signature = null): void
    {
        $data = $this->extractWebhookData($payload);
        $payment = $this->locatePayment($data['transaction_id'], $data['external_reference']);

        if (! $payment) {
            return;
        }

        $this->observability()->recordAudit($payment, 'payment_webhook_signature_failed', [
            'channel' => 'zengapay',
            'transaction_id' => $data['transaction_id'],
            'external_reference' => $data['external_reference'],
            'provided_signature' => $this->normalizeSignature((string) $signature),
        ]);

        $this->observability()->recordIssue(
            $payment,
            PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE,
            "Invalid webhook signature for payment #{$payment->id}",
            [
                'description' => 'The provider callback reached the API but failed signature verification.',
                'severity' => 'critical',
                'money_deducted' => $payment->status === Payment::STATUS_PROCESSING,
                'service_delivered' => $payment->isCompleted(),
                'metadata' => [
                    'transaction_id' => $data['transaction_id'],
                    'external_reference' => $data['external_reference'],
                    'received_at' => now()->toIso8601String(),
                ],
            ]
        );
    }

    protected function extractWebhookData(array $payload): array
    {
        $data = $payload['data'] ?? $payload['payload'] ?? $payload;

        if (! is_array($data)) {
            $data = $payload;
        }

        $transactionId = $data['transactionReference']
            ?? $data['transaction_reference']
            ?? $data['transactionId']
            ?? $data['transaction_id']
            ?? null;

        $externalReference = $data['transactionExternalReference']
            ?? $data['transaction_external_reference']
            ?? $data['externalReference']
            ?? $data['external_reference']
            ?? $payload['externalReference']
            ?? $payload['external_reference']
            ?? null;

        $status = strtolower((string) (
            $data['transactionStatus']
            ?? $data['transaction_status']
            ?? $data['status']
            ?? $payload['status']
            ?? 'unknown'
        ));

        return [
            'transaction_id' => $transactionId,
            'external_reference' => $externalReference,
            'status' => $this->normalizePaymentStatus($status),
            'reason' => $data['reason'] ?? $data['message'] ?? $payload['message'] ?? null,
            'amount' => $data['transactionAmount'] ?? $data['amount'] ?? $payload['amount'] ?? null,
            'raw' => $data,
        ];
    }

    protected function locatePayment(?string $transactionId, ?string $externalReference): ?Payment
    {
        if (! $transactionId && ! $externalReference) {
            return null;
        }

        return Payment::query()
            ->when($transactionId, function ($query) use ($transactionId) {
                $query->where('provider_transaction_id', $transactionId)
                    ->orWhere('provider_reference', $transactionId)
                    ->orWhere('transaction_reference', $transactionId);
            })
            ->when($externalReference, function ($query) use ($externalReference) {
                $query->orWhere('payment_reference', $externalReference)
                    ->orWhere('transaction_reference', $externalReference)
                    ->orWhere('provider_reference', $externalReference);
            })
            ->first();
    }

    protected function normalizeSignature(string $signature): string
    {
        $signature = trim($signature);

        if (str_contains($signature, '=')) {
            [, $signature] = array_pad(explode('=', $signature, 2), 2, '');
        }

        return trim($signature);
    }

    protected function signaturePayloadCandidates(string $payload, array $parsedPayload = []): array
    {
        $candidates = [$payload];

        if ($parsedPayload === []) {
            return $candidates;
        }

        $normalized = $this->sortPayloadRecursively($parsedPayload);
        $flat = $this->flattenPayload($normalized);

        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            $candidates[] = $json;
        }

        if ($flat !== []) {
            ksort($flat);

            $candidates[] = json_encode($flat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $candidates[] = implode('|', array_values($flat));
            $candidates[] = implode('', array_values($flat));

            $selected = array_filter([
                $flat['data.transactionReference'] ?? $flat['transactionReference'] ?? $flat['data.transactionId'] ?? $flat['transactionId'] ?? null,
                $flat['data.transactionStatus'] ?? $flat['transactionStatus'] ?? $flat['status'] ?? null,
                $flat['data.transactionAmount'] ?? $flat['transactionAmount'] ?? $flat['amount'] ?? null,
                $flat['data.transactionCurrency'] ?? $flat['transactionCurrency'] ?? $flat['currency'] ?? null,
                $flat['data.transactionExternalReference'] ?? $flat['transactionExternalReference'] ?? $flat['externalReference'] ?? null,
                $flat['data.customerPhoneNumber'] ?? $flat['customerPhoneNumber'] ?? $flat['phoneNumber'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($selected !== []) {
                $candidates[] = implode('', $selected);
                $candidates[] = implode('|', $selected);
            }
        }

        return array_values(array_unique(array_filter($candidates, fn ($candidate) => is_string($candidate) && $candidate !== '')));
    }

    protected function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened += $this->flattenPayload($value, $path);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $flattened[$path] = (string) ($value ?? '');
            }
        }

        return $flattened;
    }

    protected function sortPayloadRecursively(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortPayloadRecursively($value);
            }
        }

        ksort($payload);

        return $payload;
    }

    protected function normalizePaymentStatus(string $status): string
    {
        return match ($status) {
            'succeeded', 'successful', 'completed', 'success', 'paid' => 'completed',
            'pending', 'initiated', 'processing', 'requested', 'queued', 'indeterminate', 'received' => 'processing',
            'failed', 'failure', 'declined', 'rejected' => 'failed',
            'cancelled', 'expired', 'timeout', 'timed_out' => 'cancelled',
            'reversed', 'reversed_successfully', 'refunded' => 'refunded',
            default => 'processing',
        };
    }

    protected function stampWebhookMetadata(Payment $payment, array $payload, ?string $transactionId, ?string $externalRef, string $status): void
    {
        $payment->forceFill([
            'provider_transaction_id' => $transactionId ?? $payment->provider_transaction_id,
            'provider_reference' => $externalRef ?? $payment->provider_reference,
            'provider_response' => $payload,
            'payment_data' => array_merge($payment->payment_data ?? [], [
                'webhook_received_at' => now()->toIso8601String(),
                'webhook_status' => $status,
            ]),
        ])->save();
    }

    protected function observability(): PaymentObservabilityService
    {
        return app(PaymentObservabilityService::class);
    }
}
