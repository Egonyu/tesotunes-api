<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\Order;
use App\Models\{User, Payment};
use Illuminate\Support\Facades\DB;

/**
 * PaymentService
 * 
 * Handles payment processing with dual currency support (UGX + Credits)
 */
class PaymentService
{
    /**
     * Process order payment (supports hybrid payments)
     */
    public function processOrderPayment(Order $order, array $paymentData): Payment
    {
        return DB::transaction(function () use ($order, $paymentData) {
            $buyer = $order->buyer;
            
            // Calculate hybrid payment if using credits
            $paymentBreakdown = $this->calculateHybridPayment(
                $order->total_ugx,
                $order->total_credits,
                $buyer->credits_balance ?? 0,
                $paymentData['use_credits'] ?? false
            );
            
            // Create payment record
            $payment = Payment::create([
                'user_id' => $buyer->id,
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'payment_method' => $paymentData['payment_method'] ?? 'mobile_money',
                'provider' => $paymentData['provider'] ?? null,
                'phone_number' => $paymentData['phone_number'] ?? null,
            ]);
            
            // Set protected attributes
            $payment->amount = $paymentBreakdown['ugx_amount'];
            $payment->currency = 'UGX';
            $payment->status = Payment::STATUS_PENDING;
            $payment->transaction_id = Payment::generateTransactionId();
            $payment->payment_data = [
                'credits_used' => $paymentBreakdown['credits_used'],
                'ugx_amount' => $paymentBreakdown['ugx_amount'],
                'is_hybrid' => $paymentBreakdown['is_hybrid'],
            ];
            $payment->save();
            
            $order->update(['payment_id' => $payment->id]);
            
            return $payment;
        });
    }

    /**
     * Calculate hybrid payment breakdown
     * 
     * Determines how much to pay with credits vs UGX
     */
    public function calculateHybridPayment(
        float $totalUgx,
        int $totalCredits,
        int $availableCredits,
        bool $useCredits = false
    ): array {
        if (!$useCredits || $availableCredits === 0) {
            return [
                'credits_used' => 0,
                'ugx_amount' => $totalUgx,
                'is_hybrid' => false,
            ];
        }
        
        // Get max credits allowed (e.g., 50% of total)
        $maxCreditsPercentage = config('store.currencies.credits.max_credits_per_order_percentage', 50);
        $maxCreditsAllowed = floor($totalUgx * ($maxCreditsPercentage / 100));
        
        // Calculate credits to use
        $creditsToUse = min($availableCredits, $maxCreditsAllowed, $totalCredits);
        
        // Calculate remaining UGX to pay
        $conversionRate = config('store.currencies.credits.conversion_rate', 1);
        $ugxFromCredits = $creditsToUse * $conversionRate;
        $ugxToPay = max(0, $totalUgx - $ugxFromCredits);
        
        return [
            'credits_used' => $creditsToUse,
            'ugx_amount' => $ugxToPay,
            'is_hybrid' => $creditsToUse > 0 && $ugxToPay > 0,
        ];
    }

    /**
     * Deduct credits from user balance
     */
    public function deductCredits(User $user, int $credits): bool
    {
        if (!isset($user->credits_balance) || $user->credits_balance < $credits) {
            throw new \Exception('Insufficient credits');
        }
        
        return $user->decrement('credits_balance', $credits);
    }

    /**
     * Process mobile money payment
     */
    public function processMobileMoneyPayment(Payment $payment): bool
    {
        // TODO: Integrate with MTN/Airtel Money API
        // For now, mark as completed (you'll integrate actual API later)
        
        $payment->markAsCompleted();
        
        return true;
    }

    /**
     * Confirm payment and update order
     */
    public function confirmPayment(Payment $payment): bool
    {
        return DB::transaction(function () use ($payment) {
            $order = $payment->payable;
            
            if (!$order instanceof Order) {
                throw new \Exception('Payment is not for an order');
            }
            
            // Mark payment as completed
            $payment->markAsCompleted();
            
            // Deduct credits if used
            $creditsUsed = $payment->payment_data['credits_used'] ?? 0;
            if ($creditsUsed > 0) {
                $this->deductCredits($order->buyer, $creditsUsed);
            }
            
            // Update order
            $order->update([
                'payment_status' => Order::PAYMENT_PAID,
                'paid_ugx' => $payment->payment_data['ugx_amount'] ?? $order->total_ugx,
                'paid_credits' => $creditsUsed,
                'paid_at' => now(),
                'status' => Order::STATUS_PROCESSING,
            ]);
            
            return true;
        });
    }
}
