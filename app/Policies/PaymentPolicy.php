<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Models\AuditLog;

class PaymentPolicy
{
    /**
     * Determine whether the user can view any payments.
     */
    public function viewAny(User $user): bool
    {
        // Need payment.view permission (finance, admins)
        return $user->hasPermission('payment.view') || $user->hasPermission('payment.manage');
    }

    /**
     * Determine whether the user can view the payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        // Users can view their own payments
        if ($user->id === $payment->user_id) {
            return true;
        }

        // Finance and admins can view any payment
        return $user->hasAnyRole(['super_admin', 'admin', 'finance']) && 
               $user->hasPermission('payment.view');
    }

    /**
     * Determine whether the user can create payments.
     */
    public function create(User $user): bool
    {
        // Finance and admins can create payments
        return $user->hasPermission('payment.manage');
    }

    /**
     * Determine whether the user can update the payment.
     */
    public function update(User $user, Payment $payment): bool
    {
        // Must have payment.manage permission
        if (!$user->hasPermission('payment.manage')) {
            AuditLog::logActivity($user->id, 'unauthorized_payment_update_attempt', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
            ]);
            return false;
        }

        // Can't update completed/failed payments
        if (in_array($payment->status, ['completed', 'failed', 'refunded'])) {
            return false;
        }

        // Finance and admins can update payments
        return $user->hasAnyRole(['super_admin', 'admin', 'finance']);
    }

    /**
     * Determine whether the user can delete the payment.
     */
    public function delete(User $user, Payment $payment): bool
    {
        // Must have payment.manage permission
        if (!$user->hasPermission('payment.manage')) {
            AuditLog::logActivity($user->id, 'unauthorized_payment_delete_attempt', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
            ]);
            return false;
        }

        // Can only delete pending payments
        if ($payment->status !== 'pending') {
            return false;
        }

        // Only super admin can delete payments
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can approve the payment.
     */
    public function approve(User $user, Payment $payment): bool
    {
        // Must have payment.approve permission
        if (!$user->hasPermission('payment.approve')) {
            return false;
        }

        // Payment must be pending
        if ($payment->status !== 'pending') {
            return false;
        }

        // Finance and admins can approve
        return $user->hasAnyRole(['super_admin', 'admin', 'finance']);
    }

    /**
     * Determine whether the user can process payout.
     */
    public function processPayout(User $user, Payment $payment): bool
    {
        // Must have payment.manage permission
        if (!$user->hasPermission('payment.manage')) {
            return false;
        }

        // Only approved payments can be processed
        if ($payment->status !== 'approved') {
            return false;
        }

        // Finance role specifically can process payouts
        return $user->hasAnyRole(['super_admin', 'finance']);
    }

    /**
     * Determine whether the user can refund the payment.
     */
    public function refund(User $user, Payment $payment): bool
    {
        // Must be completed payment
        if ($payment->status !== 'completed') {
            return false;
        }

        // Only super admin and finance can issue refunds
        return $user->hasAnyRole(['super_admin', 'finance']) && 
               $user->hasPermission('payment.manage');
    }

    /**
     * Determine whether the user can restore the payment.
     */
    public function restore(User $user, Payment $payment): bool
    {
        // Only super admin can restore deleted payments
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the payment.
     */
    public function forceDelete(User $user, Payment $payment): bool
    {
        // Only super admin can permanently delete (for data cleanup)
        return $user->hasRole('super_admin');
    }
}
