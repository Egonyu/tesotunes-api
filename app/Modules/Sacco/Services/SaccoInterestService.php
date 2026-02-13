<?php

namespace App\Modules\Sacco\Services;

use App\Modules\Sacco\Models\SaccoAccount;
use App\Modules\Sacco\Models\SaccoLoan;
use App\Modules\Sacco\Models\SaccoTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SaccoInterestService
{
    /**
     * Calculate daily interest for savings account
     */
    public function calculateDailyInterest(SaccoAccount $account): float
    {
        if ($account->account_type !== 'savings') {
            return 0.0;
        }
        
        $annualRate = config('sacco.savings.interest_rate', 6.0);
        $balance = $account->balance;
        
        // Daily interest = (Balance × Annual Rate) ÷ 365
        $dailyInterest = ($balance * $annualRate) / 36500;
        
        return round($dailyInterest, 2);
    }

    /**
     * Calculate and credit interest for all savings accounts
     */
    public function creditDailyInterest(): array
    {
        $accounts = SaccoAccount::where('account_type', 'savings')
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->get();
        
        $results = [
            'processed' => 0,
            'total_interest' => 0,
            'errors' => []
        ];
        
        foreach ($accounts as $account) {
            try {
                DB::beginTransaction();
                
                $interest = $this->calculateDailyInterest($account);
                
                if ($interest > 0) {
                    // Update account balance
                    $oldBalance = $account->balance;
                    $account->balance += $interest;
                    $account->interest_earned += $interest;
                    $account->last_interest_date = now()->toDateString();
                    $account->save();
                    
                    // Create transaction record
                    SaccoTransaction::create([
                        'member_id' => $account->member_id,
                        'account_id' => $account->id,
                        'transaction_number' => $this->generateTransactionNumber(),
                        'transaction_type' => 'interest',
                        'amount' => $interest,
                        'balance_before' => $oldBalance,
                        'balance_after' => $account->balance,
                        'status' => 'completed',
                        'description' => 'Daily interest credited',
                        'processed_at' => now()
                    ]);
                    
                    $results['processed']++;
                    $results['total_interest'] += $interest;
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $results['errors'][] = "Account {$account->id}: {$e->getMessage()}";
            }
        }
        
        return $results;
    }

    /**
     * Calculate loan monthly payment (reducing balance)
     */
    public function calculateLoanMonthlyPayment(float $principal, float $annualRate, int $months): float
    {
        $monthlyRate = $annualRate / 100 / 12;
        
        if ($monthlyRate === 0.0) {
            return $principal / $months;
        }
        
        // EMI Formula: P × r × (1+r)^n / ((1+r)^n - 1)
        $payment = $principal * $monthlyRate * pow(1 + $monthlyRate, $months) 
                   / (pow(1 + $monthlyRate, $months) - 1);
        
        return round($payment, 2);
    }

    /**
     * Calculate total loan amount including interest
     */
    public function calculateTotalLoanAmount(float $principal, float $annualRate, int $months): float
    {
        $monthlyPayment = $this->calculateLoanMonthlyPayment($principal, $annualRate, $months);
        $totalAmount = $monthlyPayment * $months;
        
        return round($totalAmount, 2);
    }

    /**
     * Generate loan repayment schedule
     */
    public function generateRepaymentSchedule(SaccoLoan $loan): array
    {
        $schedule = [];
        $balance = $loan->principal_amount;
        $monthlyRate = $loan->interest_rate / 100 / 12;
        $monthlyPayment = $this->calculateLoanMonthlyPayment(
            $loan->principal_amount,
            $loan->interest_rate,
            $loan->repayment_period_months
        );
        
        $currentDate = $loan->disbursed_at ? 
            Carbon::parse($loan->disbursed_at)->addMonth() : 
            now()->addMonth();
        
        for ($i = 1; $i <= $loan->repayment_period_months; $i++) {
            $interestAmount = $balance * $monthlyRate;
            $principalAmount = $monthlyPayment - $interestAmount;
            $balance -= $principalAmount;
            
            $schedule[] = [
                'repayment_number' => $i,
                'due_date' => $currentDate->copy(),
                'amount_due' => round($monthlyPayment, 2),
                'principal_amount' => round($principalAmount, 2),
                'interest_amount' => round($interestAmount, 2),
                'balance_after' => round(max(0, $balance), 2)
            ];
            
            $currentDate->addMonth();
        }
        
        return $schedule;
    }

    /**
     * Calculate penalty for overdue payment
     */
    public function calculatePenalty(float $amount, int $daysOverdue): float
    {
        $gracePeriod = config('sacco.loans.grace_period_days', 7);
        
        if ($daysOverdue <= $gracePeriod) {
            return 0.0;
        }
        
        $penaltyRate = config('sacco.loans.penalty_rate_per_day', 0.1) / 100;
        $maxPenalty = config('sacco.loans.max_penalty_percentage', 10) / 100;
        
        $daysChargeable = $daysOverdue - $gracePeriod;
        $penalty = $amount * $penaltyRate * $daysChargeable;
        
        // Cap at maximum penalty
        $maxPenaltyAmount = $amount * $maxPenalty;
        
        return round(min($penalty, $maxPenaltyAmount), 2);
    }

    /**
     * Calculate fixed deposit interest
     */
    public function calculateFixedDepositInterest(SaccoAccount $account, int $months): float
    {
        if ($account->account_type !== 'fixed_deposit') {
            return 0.0;
        }
        
        // Tiered interest rates for fixed deposits
        $rate = $this->getFixedDepositRate($months);
        $principal = $account->balance;
        
        // Simple interest for fixed deposits
        $interest = ($principal * $rate * $months) / 1200;
        
        return round($interest, 2);
    }

    /**
     * Get fixed deposit interest rate based on duration
     */
    protected function getFixedDepositRate(int $months): float
    {
        $rates = config('sacco.fixed_deposits.interest_rates', [
            3 => 8.0,
            6 => 10.0,
            12 => 12.0,
            24 => 14.0
        ]);
        
        foreach ([24, 12, 6, 3] as $duration) {
            if ($months >= $duration) {
                return $rates[$duration] ?? 8.0;
            }
        }
        
        return 8.0;
    }

    /**
     * Generate transaction number
     */
    protected function generateTransactionNumber(): string
    {
        return 'TXN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
}
