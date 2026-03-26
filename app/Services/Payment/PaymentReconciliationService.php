<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Payment Reconciliation Service
 *
 * Detects and manages payment issues such as:
 * - Stuck processing payments (money deducted but not confirmed)
 * - Provider errors
 * - Amount mismatches
 *
 * Referenced in routes/console.php for scheduled reconciliation commands.
 */
class PaymentReconciliationService
{
    protected ZengaPayGatewayAdapter $gateway;

    public function __construct()
    {
        $this->gateway = new ZengaPayGatewayAdapter;
    }

    /**
     * Scan for payment issues across the system
     *
     * Used by: Artisan command `payment:scan-issues`
     */
    public function scanForIssues(): array
    {
        $issues = [];

        // 1. Find stuck processing payments (older than 15 minutes)
        $stuckPayments = Payment::where('status', 'processing')
            ->where('initiated_at', '<', now()->subMinutes(15))
            ->whereDoesntHave('issues', fn ($q) => $q->unresolved())
            ->get();

        foreach ($stuckPayments as $payment) {
            $issue = $this->detectIssue($payment, PaymentIssue::TYPE_STUCK_PROCESSING, [
                'money_deducted' => $payment->provider_transaction_id !== null,
                'service_delivered' => false,
                'description' => "Payment stuck in processing since {$payment->initiated_at}",
            ]);
            $issues[] = $issue;

            if (blank($payment->provider_transaction_id)) {
                $issues[] = $this->detectIssue($payment, PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE, [
                    'money_deducted' => false,
                    'service_delivered' => false,
                    'description' => 'Payment is processing but the provider transaction reference is missing, so provider reconciliation cannot start.',
                ]);
            }
        }

        // 2. Find pending payments older than 30 minutes
        $stalePending = Payment::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))
            ->whereDoesntHave('issues', fn ($q) => $q->unresolved())
            ->get();

        foreach ($stalePending as $payment) {
            $issue = $this->detectIssue($payment, PaymentIssue::TYPE_TIMEOUT, [
                'money_deducted' => false,
                'service_delivered' => false,
                'description' => "Payment pending since {$payment->created_at} — user may have abandoned",
            ]);
            $issues[] = $issue;
        }

        Log::info('Payment issue scan completed', [
            'stuck_processing' => $stuckPayments->count(),
            'stale_pending' => $stalePending->count(),
            'total_issues' => count($issues),
        ]);

        return $issues;
    }

    /**
     * Detect and create a payment issue
     *
     * Used by: console.php commands `payment:scan-historical`
     */
    public function detectIssue(Payment $payment, string $type, array $data = []): PaymentIssue
    {
        $title = match ($type) {
            PaymentIssue::TYPE_STUCK_PROCESSING => "Stuck processing: Payment #{$payment->id}",
            PaymentIssue::TYPE_PROVIDER_ERROR => "Provider error: Payment #{$payment->id}",
            PaymentIssue::TYPE_AMOUNT_MISMATCH => "Amount mismatch: Payment #{$payment->id}",
            PaymentIssue::TYPE_DUPLICATE_CHARGE => "Duplicate charge: Payment #{$payment->id}",
            PaymentIssue::TYPE_TIMEOUT => "Timeout: Payment #{$payment->id}",
            PaymentIssue::TYPE_WEBHOOK_MISSING => "Missing webhook: Payment #{$payment->id}",
            PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE => "Invalid webhook signature: Payment #{$payment->id}",
            PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE => "Missing provider reference: Payment #{$payment->id}",
            PaymentIssue::TYPE_CUSTOMER_COMPLAINT => "Customer complaint: Payment #{$payment->id}",
            default => "Issue: Payment #{$payment->id}",
        };

        $severity = match ($type) {
            PaymentIssue::TYPE_STUCK_PROCESSING, PaymentIssue::TYPE_DUPLICATE_CHARGE => 'critical',
            PaymentIssue::TYPE_PROVIDER_ERROR, PaymentIssue::TYPE_AMOUNT_MISMATCH, PaymentIssue::TYPE_INVALID_WEBHOOK_SIGNATURE, PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE => 'high',
            PaymentIssue::TYPE_TIMEOUT, PaymentIssue::TYPE_WEBHOOK_MISSING => 'medium',
            default => 'low',
        };

        return PaymentIssue::create([
            'payment_id' => $payment->id,
            'issue_type' => $type,
            'title' => $title,
            'description' => $data['description'] ?? null,
            'status' => PaymentIssue::STATUS_OPEN,
            'severity' => $severity,
            'money_deducted' => $data['money_deducted'] ?? false,
            'service_delivered' => $data['service_delivered'] ?? false,
            'provider_status' => $data['provider_status'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Investigate a specific payment issue by checking ZengaPay status
     *
     * Used by: Artisan command `payment:investigate`
     */
    public function investigate(PaymentIssue $issue): array
    {
        $payment = $issue->payment;

        if (! $payment) {
            return ['success' => false, 'message' => 'Payment not found for this issue'];
        }

        $issue->update(['status' => PaymentIssue::STATUS_INVESTIGATING]);

        // Check with ZengaPay for current status
        $transactionId = $payment->provider_transaction_id;

        if (! $transactionId) {
            $this->detectIssue($payment, PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE, [
                'money_deducted' => $payment->status === Payment::STATUS_PROCESSING,
                'service_delivered' => $payment->status === Payment::STATUS_COMPLETED,
                'description' => 'Payment investigation could not continue because the provider transaction reference is missing.',
            ]);

            return [
                'success' => false,
                'message' => 'No transaction ID available for provider lookup',
            ];
        }

        try {
            $result = $this->gateway->getTransactionStatus($transactionId);

            if (! $result['success']) {
                if (($result['error_code'] ?? null) === 'INVALID_PROVIDER_TRANSACTION_ID') {
                    $this->detectIssue($payment, PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE, [
                        'money_deducted' => $payment->status === Payment::STATUS_PROCESSING,
                        'service_delivered' => $payment->status === Payment::STATUS_COMPLETED,
                        'description' => ($result['message'] ?? 'Provider transaction identifier is invalid.')." Stored value: {$transactionId}.",
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Stored provider transaction identifier is invalid for provider lookup.',
                    ];
                }

                $this->detectIssue($payment, PaymentIssue::TYPE_PROVIDER_ERROR, [
                    'money_deducted' => $payment->status === Payment::STATUS_PROCESSING,
                    'service_delivered' => $payment->status === Payment::STATUS_COMPLETED,
                    'provider_status' => $result['status'] ?? null,
                    'description' => 'Could not retrieve provider status during reconciliation.',
                ]);

                return [
                    'success' => false,
                    'message' => 'Could not retrieve status from ZengaPay: '.($result['message'] ?? 'Unknown'),
                ];
            }

            $providerStatus = $result['status'];
            $issue->update(['provider_status' => $providerStatus]);

            // Auto-resolve based on provider status
            if ($providerStatus === 'completed' && $payment->status !== 'completed') {
                $payment->markAsCompleted([
                    'external_transaction_id' => $transactionId,
                    'payment_data' => ['reconciled' => true, 'reconciled_at' => now()->toIso8601String()],
                ]);

                app(PaymentObservabilityService::class)->resolveIssue(
                    $payment->fresh(),
                    PaymentIssue::TYPE_STUCK_PROCESSING,
                    'Provider confirmed the payment and reconciliation completed automatically.'
                );

                $issue->markAsResolved(
                    PaymentIssue::RESOLUTION_AUTO_RESOLVED,
                    'Provider confirmed payment as completed. Auto-reconciled.'
                );

                return [
                    'success' => true,
                    'message' => 'Payment auto-resolved: provider confirmed completed',
                ];
            }

            if ($providerStatus === 'failed') {
                $payment->markAsFailed('Confirmed failed by provider during reconciliation');

                app(PaymentObservabilityService::class)->resolveIssue(
                    $payment->fresh(),
                    PaymentIssue::TYPE_STUCK_PROCESSING,
                    'Provider confirmed the payment as failed during reconciliation.'
                );

                $issue->markAsResolved(
                    PaymentIssue::RESOLUTION_AUTO_RESOLVED,
                    'Provider confirmed payment as failed.'
                );

                return [
                    'success' => true,
                    'message' => 'Payment confirmed failed by provider. Issue resolved.',
                ];
            }

            // Still processing at provider
            $issue->incrementAutoResolveAttempts();

            if ($issue->auto_resolve_attempts >= 5) {
                $issue->escalate();

                return [
                    'success' => false,
                    'message' => "Payment still {$providerStatus} at provider after {$issue->auto_resolve_attempts} checks. Escalated for manual review.",
                ];
            }

            return [
                'success' => false,
                'message' => "Payment is still {$providerStatus} at provider. Will retry.",
            ];
        } catch (Exception $e) {
            Log::error('Payment investigation failed', [
                'issue_id' => $issue->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Investigation failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get reconciliation statistics
     *
     * Used by: Artisan command `payment:scan-issues`
     */
    public function getStatistics(): array
    {
        return [
            'total_issues' => PaymentIssue::count(),
            'open_issues' => PaymentIssue::where('status', PaymentIssue::STATUS_OPEN)->count(),
            'investigating' => PaymentIssue::where('status', PaymentIssue::STATUS_INVESTIGATING)->count(),
            'escalated' => PaymentIssue::where('status', PaymentIssue::STATUS_ESCALATED)->count(),
            'resolved' => PaymentIssue::where('status', PaymentIssue::STATUS_RESOLVED)->count(),
            'critical_open' => PaymentIssue::where('status', PaymentIssue::STATUS_OPEN)
                ->where('severity', 'critical')->count(),
            'stuck_processing' => Payment::where('status', 'processing')
                ->where('initiated_at', '<', now()->subMinutes(15))->count(),
            'pending_over_30m' => Payment::where('status', 'pending')
                ->where('created_at', '<', now()->subMinutes(30))->count(),
        ];
    }

    /**
     * Reconcile stuck payments by checking their status with ZengaPay
     *
     * Used by: Artisan command `payments:reconcile-stuck`
     */
    public function reconcileStuck(int $minutes = 15): array
    {
        $stuckPayments = Payment::where('status', 'processing')
            ->where('initiated_at', '<', now()->subMinutes($minutes))
            ->get();

        $results = [
            'checked' => $stuckPayments->count(),
            'resolved' => 0,
            'still_processing' => 0,
            'failed' => 0,
            'errors' => 0,
        ];

        foreach ($stuckPayments as $payment) {
            $transactionId = $payment->provider_transaction_id;

            if (! $transactionId) {
                $results['errors']++;
                app(PaymentObservabilityService::class)->recordIssue(
                    $payment,
                    PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
                    "Missing provider transaction reference for payment #{$payment->id}",
                    [
                        'description' => 'Automatic reconciliation skipped this payment because no provider transaction reference is stored.',
                        'severity' => 'high',
                        'money_deducted' => false,
                        'service_delivered' => false,
                    ]
                );

                continue;
            }

            try {
                $status = $this->gateway->getTransactionStatus($transactionId);

                if (! $status['success']) {
                    if (($status['error_code'] ?? null) === 'INVALID_PROVIDER_TRANSACTION_ID') {
                        $results['errors']++;
                        app(PaymentObservabilityService::class)->recordIssue(
                            $payment,
                            PaymentIssue::TYPE_MISSING_PROVIDER_REFERENCE,
                            "Invalid provider transaction reference for payment #{$payment->id}",
                            [
                                'description' => ($status['message'] ?? 'Provider transaction identifier is invalid.')." Stored value: {$transactionId}.",
                                'severity' => 'high',
                                'money_deducted' => $payment->status === Payment::STATUS_PROCESSING,
                                'service_delivered' => false,
                            ]
                        );

                        continue;
                    }

                    $results['errors']++;
                    app(PaymentObservabilityService::class)->recordIssue(
                        $payment,
                        PaymentIssue::TYPE_PROVIDER_ERROR,
                        "Provider reconciliation failed for payment #{$payment->id}",
                        [
                            'description' => $status['message'] ?? 'Unable to fetch provider status during reconciliation.',
                            'severity' => 'high',
                            'money_deducted' => $payment->status === Payment::STATUS_PROCESSING,
                            'service_delivered' => false,
                        ]
                    );

                    continue;
                }

                match ($status['status']) {
                    'completed' => (function () use ($payment, $transactionId, &$results) {
                        $payment->markAsCompleted([
                            'external_transaction_id' => $transactionId,
                            'payment_data' => ['reconciled' => true],
                        ]);
                        app(PaymentObservabilityService::class)->resolveTerminalStateIssues(
                            $payment->fresh(),
                            'Provider confirmed the payment during scheduled reconciliation.'
                        );
                        $results['resolved']++;
                    })(),
                    'failed', 'cancelled' => (function () use ($payment, &$results) {
                        $payment->markAsCancelled();
                        app(PaymentObservabilityService::class)->resolveTerminalStateIssues(
                            $payment->fresh(),
                            'Provider confirmed the payment as failed/cancelled during scheduled reconciliation.'
                        );
                        $results['failed']++;
                    })(),
                    default => (function () use (&$results) {
                        $results['still_processing']++;
                    })(),
                };
            } catch (Exception $e) {
                $results['errors']++;
                Log::error('Reconciliation check failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Stuck payment reconciliation completed', $results);

        return $results;
    }
}
