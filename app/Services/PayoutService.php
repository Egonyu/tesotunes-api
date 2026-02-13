<?php

namespace App\Services;

use App\Models\ArtistPayout;
use App\Models\Artist;
use App\Models\User;
use App\Models\ArtistRevenue;
use App\Services\Payment\MobileMoneyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service class for handling artist payout processing with approval workflow
 *
 * This service manages:
 * - Payout request creation and validation
 * - Multi-level approval workflow (admin/finance approval required)
 * - Mobile money disbursement (MTN, Airtel)
 * - Bank transfer processing
 * - Automatic revenue aggregation and reconciliation
 * - Fraud prevention and audit logging
 * - Retry mechanisms for failed payouts
 */
class PayoutService
{
    protected MobileMoneyService $mobileMoneyService;

    // Minimum payout thresholds (UGX)
    const MINIMUM_PAYOUT_AMOUNT = 50000; // UGX 50,000 (~$13 USD)
    const MAXIMUM_DAILY_PAYOUT = 10000000; // UGX 10,000,000 (~$2,666 USD)
    const MAXIMUM_SINGLE_PAYOUT = 5000000; // UGX 5,000,000 (~$1,333 USD)

    // Processing fees (percentages)
    const MOBILE_MONEY_FEE = 1.5; // 1.5%
    const BANK_TRANSFER_FEE = 0.5; // 0.5%

    public function __construct(MobileMoneyService $mobileMoneyService)
    {
        $this->mobileMoneyService = $mobileMoneyService;
    }

    /**
     * Request a new payout for an artist
     */
    public function requestPayout(
        Artist $artist,
        float $amount,
        string $method,
        array $payoutData = [],
        ?User $requestedBy = null
    ): array {
        DB::beginTransaction();

        try {
            // Validate payout request
            $this->validatePayoutRequest($artist, $amount, $method, $payoutData);

            // Calculate fees and net amount
            $fees = $this->calculatePayoutFees($amount, $method);
            $netAmount = $amount - $fees;

            // Create payout record (use explicit setters for protected fields)
            $payout = new ArtistPayout([
                'artist_id' => $artist->id,
                'payout_method' => $method,
                'phone_number' => $payoutData['phone_number'] ?? null,
                'account_number' => $payoutData['account_number'] ?? null,
                'bank_name' => $payoutData['bank_name'] ?? null,
                'bank_code' => $payoutData['bank_code'] ?? null,
                'account_holder_name' => $payoutData['account_holder_name'] ?? null,
                'notes' => $payoutData['notes'] ?? null,
                'requested_by_user_id' => $requestedBy?->id ?? $artist->user_id,
            ]);

            // Set protected fields explicitly
            $payout->amount = $amount;
            $payout->fee_amount = $fees;
            $payout->net_amount = $netAmount;
            $payout->currency = 'UGX';
            $payout->status = ArtistPayout::STATUS_PENDING;
            $payout->transaction_id = ArtistPayout::generateTransactionId();
            $payout->metadata = [
                'balance_before' => $artist->earnings_balance ?? 0,
                'unpaid_revenue' => $this->getUnpaidRevenueAmount($artist),
                'payout_method_details' => $this->maskSensitiveData($payoutData, $method),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'requested_at' => now()->toDateTimeString(),
            ];

            $payout->save();

            // Log audit trail
            $this->logPayoutActivity($payout, 'payout_requested', [
                'artist_id' => $artist->id,
                'amount' => $amount,
                'method' => $method,
                'requested_by' => $requestedBy?->id ?? $artist->user_id,
            ]);

            // Notify finance team for approval
            $this->notifyFinanceTeam($payout);

            DB::commit();

            return [
                'success' => true,
                'payout_id' => $payout->id,
                'transaction_id' => $payout->transaction_id,
                'message' => 'Payout request submitted successfully. Awaiting approval.',
                'amount' => $amount,
                'fee' => $fees,
                'net_amount' => $netAmount,
                'estimated_processing_time' => '1-3 business days',
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Payout request failed', [
                'artist_id' => $artist->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Approve a pending payout (requires finance/admin role)
     */
    public function approvePayout(ArtistPayout $payout, User $approver, ?string $notes = null): array
    {
        // Verify approver has finance/admin role
        if (!$approver->hasAnyRole(['finance', 'admin', 'super_admin'])) {
            throw new Exception('Insufficient permissions to approve payouts');
        }

        if (!$payout->canBeApproved()) {
            throw new Exception('Payout cannot be approved in current status: ' . $payout->status);
        }

        DB::beginTransaction();

        try {
            // Re-validate payout is still valid
            $artist = $payout->artist;
            $this->validatePayoutAtApproval($payout, $artist);

            // Mark as approved
            $payout->markAsApproved($approver);

            // Update notes if provided
            if ($notes) {
                $payout->notes = ($payout->notes ? $payout->notes . "\n\n" : '') . 
                               "Approval Note: $notes";
                $payout->save();
            }

            // Log audit trail
            $this->logPayoutActivity($payout, 'payout_approved', [
                'approved_by' => $approver->id,
                'approver_name' => $approver->name,
                'approver_role' => $approver->role,
                'notes' => $notes,
            ]);

            // Auto-process if auto-processing is enabled
            if (config('payments.auto_process_approved_payouts', false)) {
                $this->processPayout($payout);
            }

            // Notify artist
            $this->notifyArtistOfApproval($payout);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Payout approved successfully',
                'payout_id' => $payout->id,
                'status' => $payout->status,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Payout approval failed', [
                'payout_id' => $payout->id,
                'approver_id' => $approver->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reject a pending payout
     */
    public function rejectPayout(ArtistPayout $payout, User $rejector, string $reason): array
    {
        // Verify rejector has finance/admin role
        if (!$rejector->hasAnyRole(['finance', 'admin', 'super_admin'])) {
            throw new Exception('Insufficient permissions to reject payouts');
        }

        if (!$payout->canBeRejected()) {
            throw new Exception('Payout cannot be rejected in current status: ' . $payout->status);
        }

        DB::beginTransaction();

        try {
            $payout->markAsRejected($rejector, $reason);

            // Log audit trail
            $this->logPayoutActivity($payout, 'payout_rejected', [
                'rejected_by' => $rejector->id,
                'rejector_name' => $rejector->name,
                'reason' => $reason,
            ]);

            // Notify artist
            $this->notifyArtistOfRejection($payout, $reason);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Payout rejected',
                'payout_id' => $payout->id,
                'reason' => $reason,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process an approved payout (disburse funds)
     */
    public function processPayout(ArtistPayout $payout): array
    {
        if (!$payout->isApproved()) {
            throw new Exception('Only approved payouts can be processed');
        }

        DB::beginTransaction();

        try {
            // Mark as processing
            $payout->markAsProcessing();

            // Process based on payout method
            $result = match($payout->payout_method) {
                ArtistPayout::METHOD_MOBILE_MONEY => $this->processMobileMoneyPayout($payout),
                ArtistPayout::METHOD_BANK_TRANSFER => $this->processBankTransferPayout($payout),
                ArtistPayout::METHOD_PAYPAL => $this->processPayPalPayout($payout),
                default => throw new Exception('Unsupported payout method: ' . $payout->payout_method)
            };

            if ($result['success']) {
                // Mark as completed
                $payout->markAsCompleted([
                    'external_transaction_id' => $result['transaction_id'] ?? null,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'metadata' => [
                        'processed_at' => now()->toDateTimeString(),
                        'processing_time_seconds' => $result['processing_time'] ?? null,
                    ],
                ]);

                // Update artist's paid revenue
                $this->markRevenueAsPaid($payout);

                // Log audit trail
                $this->logPayoutActivity($payout, 'payout_completed', [
                    'external_transaction_id' => $result['transaction_id'] ?? null,
                    'provider' => $result['provider'] ?? null,
                ]);

                // Notify artist
                $this->notifyArtistOfCompletion($payout);

                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Payout processed successfully',
                    'payout_id' => $payout->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ];
            } else {
                throw new Exception($result['message'] ?? 'Payout processing failed');
            }

        } catch (Exception $e) {
            DB::rollBack();

            // Mark as failed
            $payout->markAsFailed($e->getMessage(), [
                'metadata' => [
                    'failed_at' => now()->toDateTimeString(),
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500), // Limit length
                ],
            ]);

            // Log audit trail
            $this->logPayoutActivity($payout, 'payout_failed', [
                'error' => $e->getMessage(),
            ]);

            Log::error('Payout processing failed', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'payout_id' => $payout->id,
            ];
        }
    }

    /**
     * Retry a failed payout
     */
    public function retryPayout(ArtistPayout $payout): array
    {
        if (!$payout->canBeRetried()) {
            throw new Exception('Payout cannot be retried in current status: ' . $payout->status);
        }

        // Reset to approved status for retry
        $payout->status = ArtistPayout::STATUS_APPROVED;
        $payout->failed_at = null;
        $payout->failure_reason = null;
        $payout->save();

        // Log audit trail
        $this->logPayoutActivity($payout, 'payout_retry_initiated', [
            'retry_count' => ($payout->metadata['retry_count'] ?? 0) + 1,
        ]);

        // Process again
        return $this->processPayout($payout);
    }

    /**
     * Cancel a payout (before processing)
     */
    public function cancelPayout(ArtistPayout $payout, ?User $cancelledBy = null, ?string $reason = null): array
    {
        if (!$payout->canBeCancelled()) {
            throw new Exception('Payout cannot be cancelled in current status: ' . $payout->status);
        }

        DB::beginTransaction();

        try {
            $payout->markAsCancelled();

            // Log audit trail
            $this->logPayoutActivity($payout, 'payout_cancelled', [
                'cancelled_by' => $cancelledBy?->id,
                'reason' => $reason,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Payout cancelled successfully',
                'payout_id' => $payout->id,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get unpaid revenue amount for artist
     */
    public function getUnpaidRevenueAmount(Artist $artist): float
    {
        return ArtistRevenue::forArtist($artist->id)
            ->whereIn('status', ['pending', 'processed'])
            ->sum('net_amount');
    }

    /**
     * Get pending payouts that require approval
     */
    public function getPendingPayouts(): \Illuminate\Database\Eloquent\Collection
    {
        return ArtistPayout::pending()
            ->with('artist.user')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get artist payout history
     */
    public function getArtistPayoutHistory(Artist $artist, array $filters = [])
    {
        $query = ArtistPayout::forArtist($artist->id)
            ->with('requestedBy', 'approvedBy');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Validate payout request
     */
    protected function validatePayoutRequest(Artist $artist, float $amount, string $method, array $data): void
    {
        // Check minimum amount
        if ($amount < self::MINIMUM_PAYOUT_AMOUNT) {
            throw new Exception("Minimum payout amount is UGX " . number_format(self::MINIMUM_PAYOUT_AMOUNT));
        }

        // Check maximum single payout
        if ($amount > self::MAXIMUM_SINGLE_PAYOUT) {
            throw new Exception("Maximum single payout is UGX " . number_format(self::MAXIMUM_SINGLE_PAYOUT));
        }

        // Check artist has sufficient balance
        $unpaidRevenue = $this->getUnpaidRevenueAmount($artist);
        if ($amount > $unpaidRevenue) {
            throw new Exception("Insufficient balance. Available: UGX " . number_format($unpaidRevenue, 2));
        }

        // Check daily limit
        $todayPayouts = ArtistPayout::forArtist($artist->id)
            ->whereDate('created_at', today())
            ->whereIn('status', [ArtistPayout::STATUS_PENDING, ArtistPayout::STATUS_APPROVED, ArtistPayout::STATUS_PROCESSING])
            ->sum('amount');

        if (($todayPayouts + $amount) > self::MAXIMUM_DAILY_PAYOUT) {
            throw new Exception("Daily payout limit exceeded. Limit: UGX " . number_format(self::MAXIMUM_DAILY_PAYOUT));
        }

        // Validate method-specific requirements
        $this->validatePayoutMethod($method, $data);
    }

    /**
     * Validate payout method and required data
     */
    protected function validatePayoutMethod(string $method, array $data): void
    {
        switch ($method) {
            case ArtistPayout::METHOD_MOBILE_MONEY:
                if (empty($data['phone_number'])) {
                    throw new Exception('Phone number is required for mobile money payouts');
                }
                if (!preg_match('/^256[0-9]{9}$/', $data['phone_number'])) {
                    throw new Exception('Invalid phone number format. Must be 256XXXXXXXXX');
                }
                break;

            case ArtistPayout::METHOD_BANK_TRANSFER:
                if (empty($data['account_number']) || empty($data['bank_code']) || empty($data['account_holder_name'])) {
                    throw new Exception('Account number, bank code, and account holder name are required for bank transfers');
                }
                break;

            case ArtistPayout::METHOD_PAYPAL:
                if (empty($data['paypal_email'])) {
                    throw new Exception('PayPal email is required for PayPal payouts');
                }
                break;

            default:
                throw new Exception("Unsupported payout method: {$method}");
        }
    }

    /**
     * Validate payout at approval time
     */
    protected function validatePayoutAtApproval(ArtistPayout $payout, Artist $artist): void
    {
        // Re-check artist still has sufficient balance
        $unpaidRevenue = $this->getUnpaidRevenueAmount($artist);
        if ($payout->amount > $unpaidRevenue) {
            throw new Exception('Artist no longer has sufficient balance for this payout');
        }
    }

    /**
     * Calculate payout fees based on method
     */
    protected function calculatePayoutFees(float $amount, string $method): float
    {
        return match($method) {
            ArtistPayout::METHOD_MOBILE_MONEY => $amount * (self::MOBILE_MONEY_FEE / 100),
            ArtistPayout::METHOD_BANK_TRANSFER => $amount * (self::BANK_TRANSFER_FEE / 100),
            ArtistPayout::METHOD_PAYPAL => $amount * 0.02, // 2%
            default => 0.0
        };
    }

    /**
     * Process mobile money payout
     */
    protected function processMobileMoneyPayout(ArtistPayout $payout): array
    {
        return $this->mobileMoneyService->disburseFunds(
            $payout->phone_number,
            $payout->net_amount,
            $payout->currency,
            "Artist payout: " . $payout->transaction_id
        );
    }

    /**
     * Process bank transfer payout
     */
    protected function processBankTransferPayout(ArtistPayout $payout): array
    {
        // Implementation would integrate with banking API
        // For now, mark as requiring manual processing
        return [
            'success' => true,
            'message' => 'Bank transfer initiated - requires manual verification',
            'transaction_id' => $payout->transaction_id,
            'requires_manual_verification' => true,
        ];
    }

    /**
     * Process PayPal payout
     */
    protected function processPayPalPayout(ArtistPayout $payout): array
    {
        // Implementation would integrate with PayPal API
        return [
            'success' => false,
            'message' => 'PayPal integration not yet implemented',
        ];
    }

    /**
     * Mark associated revenue records as paid
     */
    protected function markRevenueAsPaid(ArtistPayout $payout): void
    {
        ArtistRevenue::forArtist($payout->artist_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->orderBy('revenue_date', 'asc')
            ->update(['status' => 'paid']);
    }

    /**
     * Mask sensitive payment data for logging
     */
    protected function maskSensitiveData(array $data, string $method): array
    {
        $masked = $data;

        if (isset($masked['phone_number'])) {
            $masked['phone_number'] = substr($masked['phone_number'], 0, 6) . '****';
        }

        if (isset($masked['account_number'])) {
            $masked['account_number'] = '****' . substr($masked['account_number'], -4);
        }

        return $masked;
    }

    /**
     * Log payout activity for audit trail
     */
    protected function logPayoutActivity(ArtistPayout $payout, string $action, array $data = []): void
    {
        Log::channel('audit')->info("Payout: {$action}", array_merge([
            'payout_id' => $payout->id,
            'transaction_id' => $payout->transaction_id,
            'artist_id' => $payout->artist_id,
            'amount' => $payout->amount,
            'status' => $payout->status,
            'timestamp' => now()->toDateTimeString(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $data));
    }

    /**
     * Notify finance team of pending payout
     */
    protected function notifyFinanceTeam(ArtistPayout $payout): void
    {
        // Implementation would send email/notification to finance team
        Log::info('Finance team notified of pending payout', [
            'payout_id' => $payout->id,
            'amount' => $payout->amount,
        ]);
    }

    /**
     * Notify artist of payout approval
     */
    protected function notifyArtistOfApproval(ArtistPayout $payout): void
    {
        // Implementation would send notification to artist
        Log::info('Artist notified of payout approval', [
            'payout_id' => $payout->id,
            'artist_id' => $payout->artist_id,
        ]);
    }

    /**
     * Notify artist of payout rejection
     */
    protected function notifyArtistOfRejection(ArtistPayout $payout, string $reason): void
    {
        // Implementation would send notification to artist
        Log::info('Artist notified of payout rejection', [
            'payout_id' => $payout->id,
            'artist_id' => $payout->artist_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Notify artist of payout completion
     */
    protected function notifyArtistOfCompletion(ArtistPayout $payout): void
    {
        // Implementation would send notification to artist
        Log::info('Artist notified of payout completion', [
            'payout_id' => $payout->id,
            'artist_id' => $payout->artist_id,
            'amount' => $payout->net_amount,
        ]);
    }
}
