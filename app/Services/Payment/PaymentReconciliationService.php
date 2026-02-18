<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PaymentIssue;
use App\Services\Payment\Adapters\ZengaPayGatewayAdapter;
use Illuminate\Support\Facades\Log;
use Exception;

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
        $this->gateway = new ZengaPayGatewayAdapter();
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
            ->whereDoesntHave('issues', fn($q) => $q->unresolved())
            ->get();

        foreach ($stuckPayments as $payment) {
            $issue = $this->detectIssue($payment, PaymentIssue::TYPE_STUCK_PROCESSING, [
                'money_deducted' => true,
                'service_delivered' => false,
                'description' => "Payment stuck in processing since {$payment->initiated_at}",
            ]);
            $issues[] = $issue;
        }

        // 2. Find pending payments older than 30 minutes
        $stalePending = Payment::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))
            ->whereDoesntHave('issues', fn($q) => $q->unresolved())
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
            default => "Issue: Payment #{$payment->id}",
        };

        $severity = match ($type) {
            PaymentIssue::TYPE_STUCK_PROCESSING, PaymentIssue::TYPE_DUPLICATE_CHARGE => 'critical',
            PaymentIssue::TYPE_PROVIDER_ERROR, PaymentIssue::TYPE_AMOUNT_MISMATCH => 'high',
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

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found for this issue'];
        }

        $issue->update(['status' => PaymentIssue::STATUS_INVESTIGATING]);

        // Check with ZengaPay for current status
        $transactionId = $payment->provider_transaction_id ?? $payment->transaction_reference;

        if (!$transactionId) {
            return [
                'success' => false,
                'message' => 'No transaction ID available for provider lookup',
            ];
        }

        try {
            $result = $this->gateway->getTransactionStatus($transactionId);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => 'Could not retrieve status from ZengaPay: ' . ($result['message'] ?? 'Unknown'),
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

                $issue->markAsResolved(
                    PaymentIssue::RESOLUTION_AUTO_RESOLVED,
                    "Provider confirmed payment as completed. Auto-reconciled."
                );

                return [
                    'success' => true,
                    'message' => "Payment auto-resolved: provider confirmed completed",
                ];
            }

            if ($providerStatus === 'failed') {
                $payment->markAsFailed('Confirmed failed by provider during reconciliation');

                $issue->markAsResolved(
                    PaymentIssue::RESOLUTION_AUTO_RESOLVED,
                    "Provider confirmed payment as failed."
                );

                return [
                    'success' => true,
                    'message' => "Payment confirmed failed by provider. Issue resolved.",
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
                'message' => 'Investigation failed: ' . $e->getMessage(),
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
            $transactionId = $payment->provider_transaction_id ?? $payment->transaction_reference;

            if (!$transactionId) {
                $results['errors']++;
                continue;
            }

            try {
                $status = $this->gateway->getTransactionStatus($transactionId);

                if (!$status['success']) {
                    $results['errors']++;
                    continue;
                }

                match ($status['status']) {
                    'completed' => (function () use ($payment, $transactionId, &$results) {
                        $payment->markAsCompleted([
                            'external_transaction_id' => $transactionId,
                            'payment_data' => ['reconciled' => true],
                        ]);
                        $results['resolved']++;
                    })(),
                    'failed', 'cancelled' => (function () use ($payment, $status, &$results) {
                        $payment->markAsFailed('Confirmed failed/cancelled during reconciliation');
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
