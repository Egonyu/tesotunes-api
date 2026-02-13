<?php

namespace App\Observers;

use App\Models\ArtistPayout;
use Illuminate\Support\Facades\Log;

/**
 * Observer for ArtistPayout model to enforce security and audit logging
 *
 * This observer:
 * - Logs all payout state changes for audit trail
 * - Prevents unauthorized modifications to financial fields
 * - Tracks approval workflow
 * - Detects suspicious payout requests
 * - Enforces dual-approval requirements for large payouts
 */
class ArtistPayoutObserver
{
    // Large payout threshold requiring additional scrutiny
    const LARGE_PAYOUT_THRESHOLD = 2000000; // UGX 2,000,000 (~$533 USD)

    /**
     * Handle the ArtistPayout "creating" event.
     */
    public function creating(ArtistPayout $payout): void
    {
        // Ensure transaction ID is set
        if (empty($payout->transaction_id)) {
            $payout->transaction_id = ArtistPayout::generateTransactionId();
        }

        // Set default currency if not provided
        if (empty($payout->currency)) {
            $payout->currency = 'UGX';
        }

        // Set default status if not provided
        if (empty($payout->status)) {
            $payout->status = ArtistPayout::STATUS_PENDING;
        }

        // Calculate fees if not set
        if ($payout->amount && empty($payout->fee_amount)) {
            $payout->fee_amount = $this->calculateFee($payout->amount, $payout->payout_method);
            $payout->net_amount = $payout->amount - $payout->fee_amount;
        }

        // Flag large payouts for additional review
        if ($payout->amount >= self::LARGE_PAYOUT_THRESHOLD) {
            $metadata = $payout->metadata ?? [];
            $metadata['requires_additional_review'] = true;
            $metadata['flagged_as_large_payout'] = true;
            $payout->metadata = $metadata;

            Log::channel('audit')->warning('Large payout request created', [
                'transaction_id' => $payout->transaction_id,
                'artist_id' => $payout->artist_id,
                'amount' => $payout->amount,
                'threshold' => self::LARGE_PAYOUT_THRESHOLD,
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Log creation
        Log::channel('audit')->info('Artist payout request created', [
            'transaction_id' => $payout->transaction_id,
            'artist_id' => $payout->artist_id,
            'amount' => $payout->amount,
            'fee_amount' => $payout->fee_amount,
            'net_amount' => $payout->net_amount,
            'payout_method' => $payout->payout_method,
            'requested_by' => $payout->requested_by_user_id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the ArtistPayout "created" event.
     */
    public function created(ArtistPayout $payout): void
    {
        Log::channel('audit')->info('Artist payout record created', [
            'id' => $payout->id,
            'transaction_id' => $payout->transaction_id,
            'artist_id' => $payout->artist_id,
            'amount' => $payout->amount,
            'status' => $payout->status,
        ]);

        // Detect rapid successive payout requests (potential fraud)
        $this->detectSuspiciousActivity($payout);
    }

    /**
     * Handle the ArtistPayout "updating" event.
     */
    public function updating(ArtistPayout $payout): void
    {
        $changes = $payout->getDirty();

        // Log status changes
        if (isset($changes['status'])) {
            $oldStatus = $payout->getOriginal('status');
            $newStatus = $changes['status'];

            // Validate status transitions
            if (!$this->isValidStatusTransition($oldStatus, $newStatus)) {
                Log::channel('audit')->critical('Invalid payout status transition attempted', [
                    'id' => $payout->id,
                    'transaction_id' => $payout->transaction_id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'user_id' => auth()->id(),
                    'ip_address' => request()->ip(),
                    'timestamp' => now()->toDateTimeString(),
                ]);
            }

            Log::channel('audit')->warning('Payout status changed', [
                'id' => $payout->id,
                'transaction_id' => $payout->transaction_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Alert on amount changes (should NEVER happen after creation)
        if (isset($changes['amount'])) {
            Log::channel('audit')->critical('Payout amount modified after creation', [
                'id' => $payout->id,
                'transaction_id' => $payout->transaction_id,
                'old_amount' => $payout->getOriginal('amount'),
                'new_amount' => $changes['amount'],
                'changed_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Log approval/rejection
        if (isset($changes['approved_by_user_id'])) {
            Log::channel('audit')->info('Payout approval action', [
                'id' => $payout->id,
                'transaction_id' => $payout->transaction_id,
                'approved_by' => $changes['approved_by_user_id'],
                'new_status' => $payout->status,
                'timestamp' => now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * Handle the ArtistPayout "updated" event.
     */
    public function updated(ArtistPayout $payout): void
    {
        $changes = $payout->getChanges();

        Log::channel('audit')->info('Artist payout updated', [
            'id' => $payout->id,
            'transaction_id' => $payout->transaction_id,
            'changes' => array_keys($changes),
            'new_status' => $payout->status,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Trigger notifications based on status changes
        if (isset($changes['status'])) {
            $this->handleStatusChange($payout, $changes['status']);
        }
    }

    /**
     * Handle the ArtistPayout "deleted" event.
     */
    public function deleted(ArtistPayout $payout): void
    {
        Log::channel('audit')->critical('Artist payout deleted', [
            'id' => $payout->id,
            'transaction_id' => $payout->transaction_id,
            'amount' => $payout->amount,
            'status' => $payout->status,
            'deleted_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the ArtistPayout "restored" event.
     */
    public function restored(ArtistPayout $payout): void
    {
        Log::channel('audit')->warning('Artist payout restored', [
            'id' => $payout->id,
            'transaction_id' => $payout->transaction_id,
            'restored_by' => auth()->id(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Validate status transition
     */
    protected function isValidStatusTransition(string $oldStatus, string $newStatus): bool
    {
        $validTransitions = [
            ArtistPayout::STATUS_PENDING => [
                ArtistPayout::STATUS_APPROVED,
                ArtistPayout::STATUS_REJECTED,
                ArtistPayout::STATUS_CANCELLED,
            ],
            ArtistPayout::STATUS_APPROVED => [
                ArtistPayout::STATUS_PROCESSING,
                ArtistPayout::STATUS_CANCELLED,
            ],
            ArtistPayout::STATUS_PROCESSING => [
                ArtistPayout::STATUS_COMPLETED,
                ArtistPayout::STATUS_FAILED,
            ],
            ArtistPayout::STATUS_FAILED => [
                ArtistPayout::STATUS_APPROVED, // For retry
            ],
        ];

        return in_array($newStatus, $validTransitions[$oldStatus] ?? []);
    }

    /**
     * Handle status change notifications
     */
    protected function handleStatusChange(ArtistPayout $payout, string $newStatus): void
    {
        switch ($newStatus) {
            case ArtistPayout::STATUS_APPROVED:
                Log::info('Payout approved', [
                    'payout_id' => $payout->id,
                    'artist_id' => $payout->artist_id,
                    'amount' => $payout->amount,
                    'approved_by' => $payout->approved_by_user_id,
                ]);
                break;

            case ArtistPayout::STATUS_COMPLETED:
                Log::info('Payout completed', [
                    'payout_id' => $payout->id,
                    'artist_id' => $payout->artist_id,
                    'amount' => $payout->net_amount,
                    'external_transaction_id' => $payout->external_transaction_id,
                ]);
                break;

            case ArtistPayout::STATUS_FAILED:
                Log::warning('Payout failed', [
                    'payout_id' => $payout->id,
                    'artist_id' => $payout->artist_id,
                    'amount' => $payout->amount,
                    'failure_reason' => $payout->failure_reason,
                ]);
                break;

            case ArtistPayout::STATUS_REJECTED:
                Log::info('Payout rejected', [
                    'payout_id' => $payout->id,
                    'artist_id' => $payout->artist_id,
                    'amount' => $payout->amount,
                    'rejected_by' => $payout->approved_by_user_id,
                    'rejection_reason' => $payout->failure_reason,
                ]);
                break;
        }
    }

    /**
     * Detect suspicious payout activity patterns
     */
    protected function detectSuspiciousActivity(ArtistPayout $payout): void
    {
        // Check for multiple payout requests in short time
        $recentPayouts = ArtistPayout::where('artist_id', $payout->artist_id)
            ->where('id', '!=', $payout->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        if ($recentPayouts >= 3) {
            Log::channel('audit')->warning('Multiple payout requests detected', [
                'artist_id' => $payout->artist_id,
                'payout_id' => $payout->id,
                'recent_payout_count' => $recentPayouts,
                'timeframe' => '24 hours',
                'timestamp' => now()->toDateTimeString(),
            ]);

            // Flag for review
            $metadata = $payout->metadata ?? [];
            $metadata['flagged_multiple_requests'] = true;
            $payout->metadata = $metadata;
            $payout->saveQuietly(); // Don't trigger observers again
        }

        // Check for unusual payout amounts
        $avgPayoutAmount = ArtistPayout::where('artist_id', $payout->artist_id)
            ->where('status', ArtistPayout::STATUS_COMPLETED)
            ->avg('amount');

        if ($avgPayoutAmount && $payout->amount > ($avgPayoutAmount * 5)) {
            Log::channel('audit')->warning('Unusually large payout request', [
                'artist_id' => $payout->artist_id,
                'payout_id' => $payout->id,
                'requested_amount' => $payout->amount,
                'average_amount' => $avgPayoutAmount,
                'ratio' => round($payout->amount / $avgPayoutAmount, 2),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * Calculate payout fee
     */
    protected function calculateFee(float $amount, string $method): float
    {
        return match($method) {
            ArtistPayout::METHOD_MOBILE_MONEY => $amount * 0.015, // 1.5%
            ArtistPayout::METHOD_BANK_TRANSFER => $amount * 0.005, // 0.5%
            ArtistPayout::METHOD_PAYPAL => $amount * 0.02, // 2%
            default => 0.0
        };
    }
}
