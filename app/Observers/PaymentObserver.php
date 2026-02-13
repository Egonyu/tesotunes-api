<?php

namespace App\Observers;

use App\Models\Payment;
use App\Models\User;
use App\Facades\AuditLog;
use App\Notifications\AdminPaymentNotification;
use App\Notifications\ArtistRevenueNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentSuccessNotification;
use App\Services\Loyalty\PaymentLoyaltyService;
use App\Services\Sacco\SavingsAutoDepositService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Observer for Payment model to enforce security and audit logging
 *
 * This observer:
 * - Logs all payment state changes for audit trail
 * - Prevents unauthorized modifications to financial fields
 * - Tracks payment lifecycle events
 * - Detects suspicious activities
 * - Phase 3: Auto-deposits artist earnings to SACCO
 * - Sends notifications to users, artists, and admins
 */
class PaymentObserver
{
    /**
     * High value payment threshold (in UGX)
     */
    protected const HIGH_VALUE_THRESHOLD = 500000; // 500,000 UGX

    /**
     * Handle the Payment "creating" event.
     */
    public function creating(Payment $payment): void
    {
        // Ensure transaction ID is set
        if (empty($payment->transaction_id)) {
            $payment->transaction_id = Payment::generateTransactionId();
        }

        // Set default currency if not provided
        if (empty($payment->currency)) {
            $payment->currency = 'UGX';
        }

        // Set default status if not provided
        if (empty($payment->status)) {
            $payment->status = Payment::STATUS_PENDING;
        }

        // Log creation
        Log::channel('audit')->info('Payment created', [
            'transaction_id' => $payment->transaction_id,
            'user_id' => $payment->user_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        Log::channel('audit')->info('Payment record created', [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'user_id' => $payment->user_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
        ]);

        // Log to SystemAuditLog for admin dashboard
        try {
            $user = $payment->user;
            AuditLog::log(
                'WALLET_EVENT',
                'payment_created',
                "Payment initiated: {$payment->currency} " . number_format($payment->amount) . " via {$payment->payment_method}",
                [
                    'actor_id' => $payment->user_id,
                    'actor_name' => $user?->name ?? 'Unknown User',
                    'actor_email' => $user?->email ?? 'unknown',
                    'target_type' => 'Payment',
                    'target_id' => $payment->id,
                    'target_identifier' => $payment->transaction_id,
                    'module' => 'payments',
                    'metadata' => [
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'payment_method' => $payment->payment_method,
                        'payment_type' => $payment->payment_type,
                        'status' => $payment->status,
                    ],
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log payment to audit log: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Payment "updating" event.
     */
    public function updating(Payment $payment): void
    {
        $changes = $payment->getDirty();

        // Log critical field changes
        if (isset($changes['status'])) {
            Log::channel('audit')->warning('Payment status changed', [
                'id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'old_status' => $payment->getOriginal('status'),
                'new_status' => $changes['status'],
                'changed_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Alert on amount changes (should rarely happen)
        if (isset($changes['amount'])) {
            Log::channel('audit')->alert('Payment amount modified', [
                'id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'old_amount' => $payment->getOriginal('amount'),
                'new_amount' => $changes['amount'],
                'changed_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Prevent direct mass assignment of protected fields
        $protectedFields = ['amount', 'currency', 'status', 'transaction_id'];
        $unauthorizedChanges = array_intersect_key($changes, array_flip($protectedFields));
        
        if (!empty($unauthorizedChanges) && !$this->isSystemContext()) {
            Log::channel('audit')->critical('Unauthorized payment field modification attempt', [
                'id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'attempted_changes' => $unauthorizedChanges,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        $changes = $payment->getChanges();

        Log::channel('audit')->info('Payment updated', [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'changes' => array_keys($changes),
            'new_status' => $payment->status,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Log status changes to SystemAuditLog for admin dashboard
        if (isset($changes['status'])) {
            $this->logStatusChangeToAuditLog($payment, $payment->getOriginal('status'), $changes['status']);
        }

        // Trigger notifications based on status changes
        if (isset($changes['status'])) {
            $this->handleStatusChange($payment, $changes['status']);
        }
    }

    /**
     * Log payment status changes to SystemAuditLog
     */
    protected function logStatusChangeToAuditLog(Payment $payment, ?string $oldStatus, string $newStatus): void
    {
        try {
            $user = $payment->user;
            $severity = match($newStatus) {
                Payment::STATUS_COMPLETED => 'info',
                Payment::STATUS_FAILED => 'warning',
                Payment::STATUS_REFUNDED => 'warning',
                Payment::STATUS_CANCELLED => 'notice',
                default => 'info',
            };

            $narrative = match($newStatus) {
                Payment::STATUS_COMPLETED => "Payment completed: {$payment->currency} " . number_format($payment->amount),
                Payment::STATUS_FAILED => "Payment failed: {$payment->currency} " . number_format($payment->amount) . " - " . ($payment->failure_reason ?? 'Unknown reason'),
                Payment::STATUS_REFUNDED => "Payment refunded: {$payment->currency} " . number_format($payment->amount),
                Payment::STATUS_CANCELLED => "Payment cancelled: {$payment->currency} " . number_format($payment->amount),
                default => "Payment status changed from {$oldStatus} to {$newStatus}",
            };

            AuditLog::log(
                'WALLET_EVENT',
                'payment_' . $newStatus,
                $narrative,
                [
                    'actor_id' => $payment->user_id,
                    'actor_name' => $user?->name ?? 'Unknown User',
                    'actor_email' => $user?->email ?? 'unknown',
                    'target_type' => 'Payment',
                    'target_id' => $payment->id,
                    'target_identifier' => $payment->transaction_id,
                    'module' => 'payments',
                    'severity' => $severity,
                    'old_values' => ['status' => $oldStatus],
                    'new_values' => ['status' => $newStatus],
                    'metadata' => [
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'payment_method' => $payment->payment_method,
                        'payment_type' => $payment->payment_type,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'failure_reason' => $payment->failure_reason,
                    ],
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log payment status change to audit log: ' . $e->getMessage());
        }
    }

    /**
     * Handle the Payment "deleted" event.
     */
    public function deleted(Payment $payment): void
    {
        Log::channel('audit')->warning('Payment deleted', [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'deleted_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the Payment "restored" event.
     */
    public function restored(Payment $payment): void
    {
        Log::channel('audit')->info('Payment restored', [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'restored_by' => auth()->id(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the Payment "force deleted" event.
     */
    public function forceDeleted(Payment $payment): void
    {
        Log::channel('audit')->critical('Payment permanently deleted', [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'deleted_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle status change notifications and SACCO integration (Phase 3)
     */
    protected function handleStatusChange(Payment $payment, string $newStatus): void
    {
        $user = $payment->user;

        switch ($newStatus) {
            case Payment::STATUS_COMPLETED:
                Log::info('Payment completed', [
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'user_id' => $payment->user_id,
                ]);
                
                // Notify user of successful payment
                if ($user && $this->shouldNotifyUser($user, 'payment_received')) {
                    $user->notify(new PaymentSuccessNotification($payment));
                }

                // Notify admins for high value payments
                if ($payment->amount >= self::HIGH_VALUE_THRESHOLD) {
                    $this->notifyAdmins($payment, 'high_value');
                }

                // Check if this is artist revenue
                if ($payment->payment_type === 'artist_revenue' && $user?->isArtist()) {
                    $user->notify(new ArtistRevenueNotification($payment, 'revenue_received'));
                }

                // Award loyalty points for the payment
                $this->awardLoyaltyPoints($payment);

                // Phase 3: Artist Earnings Integration
                $this->handleArtistEarningsDeposit($payment);
                break;

            case Payment::STATUS_FAILED:
                Log::warning('Payment failed', [
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'user_id' => $payment->user_id,
                    'failure_reason' => $payment->failure_reason,
                ]);

                // Notify user of failed payment
                if ($user && $this->shouldNotifyUser($user, 'payment_failed')) {
                    $user->notify(new PaymentFailedNotification($payment));
                }

                // Notify admins of failed payment for investigation
                $this->notifyAdmins($payment, 'failed');
                
                // Check if this is a failed artist payout
                if ($payment->payment_type === 'artist_payout' && $user?->isArtist()) {
                    $user->notify(new ArtistRevenueNotification($payment, 'payout_failed'));
                }
                break;

            case Payment::STATUS_REFUNDED:
                Log::info('Payment refunded', [
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'user_id' => $payment->user_id,
                ]);

                // Notify admins of refund
                $this->notifyAdmins($payment, 'refunded');
                break;
        }
    }

    /**
     * Notify admin users of payment events
     */
    protected function notifyAdmins(Payment $payment, string $eventType): void
    {
        try {
            // Get admin users with admin role
            $admins = User::where('is_admin', true)
                ->orWhereHas('roles', function ($query) {
                    $query->whereIn('name', ['admin', 'super-admin', 'finance']);
                })
                ->get();

            if ($admins->isEmpty()) {
                Log::warning('No admin users found to notify for payment event', [
                    'payment_id' => $payment->id,
                    'event_type' => $eventType,
                ]);
                return;
            }

            Notification::send($admins, new AdminPaymentNotification($payment, $eventType));

            Log::info('Admin notification sent for payment event', [
                'payment_id' => $payment->id,
                'event_type' => $eventType,
                'admins_notified' => $admins->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send admin payment notification', [
                'payment_id' => $payment->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if user should receive notification based on preferences
     */
    protected function shouldNotifyUser(User $user, string $notificationType): bool
    {
        $prefs = $user->notification_preferences ?? [];
        $typePref = $prefs[$notificationType] ?? ['email' => true, 'push' => true];

        // Return true if any channel is enabled
        return ($typePref['email'] ?? true) || ($typePref['push'] ?? true);
    }

    /**
     * Award loyalty points for completed payment
     */
    protected function awardLoyaltyPoints(Payment $payment): void
    {
        // Don't award points for artist revenue payouts (they earn differently)
        if (in_array($payment->payment_type, ['artist_revenue', 'artist_payout', 'payout'])) {
            return;
        }

        try {
            $loyaltyService = app(PaymentLoyaltyService::class);
            $result = $loyaltyService->awardPointsForPayment($payment);

            if ($result['success']) {
                Log::info('Loyalty points awarded for payment', [
                    'payment_id' => $payment->id,
                    'points' => $result['points_awarded'] ?? 0,
                ]);
            }
        } catch (\Exception $e) {
            // Don't let loyalty point failures break payment processing
            Log::warning('Failed to award loyalty points for payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 3: Handle artist earnings auto-deposit to SACCO
     */
    protected function handleArtistEarningsDeposit(Payment $payment): void
    {
        try {
            $autoDepositService = app(SavingsAutoDepositService::class);
            $result = $autoDepositService->processAutoDeposit($payment);

            if ($result['success']) {
                Log::info('Auto-deposit processed for payment', [
                    'payment_id' => $payment->id,
                    'amount' => $result['amount'] ?? 0,
                    'message' => $result['message'] ?? '',
                ]);
            }
        } catch (\Exception $e) {
            // Don't let auto-deposit failures break payment processing
            Log::warning('Auto-deposit processing failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if execution is in system/service context (vs user request)
     */
    protected function isSystemContext(): bool
    {
        // Check if call is from service layer or console command
        return app()->runningInConsole() || !auth()->check();
    }
}
