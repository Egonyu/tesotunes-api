<?php

namespace App\Services\Sacco;

use App\Models\Sacco\SaccoAccount;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SaccoInterestService
{
    /**
     * Calculate daily interest for a savings account
     */
    public function calculateDailyInterest(SaccoAccount $account): float
    {
        if ($account->account_type !== 'savings') {
            return 0.0;
        }

        $annualRate = config('sacco.savings.interest_rate', 6.0);
        $dailyInterest = ($account->balance * $annualRate) / 36500;

        return round($dailyInterest, 2);
    }

    /**
     * Calculate and credit daily interest for all active savings accounts
     */
    public function creditDailyInterest(): array
    {
        $accounts = SaccoAccount::where('account_type', 'savings')
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->get();

        $results = [
            'processed' => 0,
            'total_interest' => 0.0,
            'errors' => [],
        ];

        foreach ($accounts as $account) {
            try {
                DB::beginTransaction();

                $interest = $this->calculateDailyInterest($account);

                if ($interest > 0) {
                    $oldBalance = $account->balance;
                    $account->balance += $interest;
                    $account->interest_earned += $interest;
                    $account->last_interest_date = now()->toDateString();
                    $account->save();

                    SaccoTransaction::create([
                        'member_id' => $account->member_id,
                        'account_id' => $account->id,
                        'transaction_type' => 'interest',
                        'amount' => $interest,
                        'balance_before' => $oldBalance,
                        'balance_after' => $account->balance,
                        'description' => 'Daily interest credited',
                    ]);

                    $results['processed']++;
                    $results['total_interest'] += $interest;
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $results['errors'][] = "Account {$account->id}: {$e->getMessage()}";
            }
        }

        return $results;
    }

    /**
     * Calculate monthly EMI using reducing balance formula
     */
    public function calculateLoanMonthlyPayment(float $principal, float $annualRate, int $months): float
    {
        $monthlyRate = $annualRate / 100 / 12;

        if ($monthlyRate === 0.0) {
            return round($principal / $months, 2);
        }

        $payment = $principal * $monthlyRate * pow(1 + $monthlyRate, $months)
                   / (pow(1 + $monthlyRate, $months) - 1);

        return round($payment, 2);
    }

    public function calculateTotalLoanAmount(float $principal, float $annualRate, int $months): float
    {
        return round($this->calculateLoanMonthlyPayment($principal, $annualRate, $months) * $months, 2);
    }

    /**
     * Generate amortisation schedule for a loan
     */
    public function generateRepaymentSchedule(SaccoLoan $loan): array
    {
        $termMonths = $loan->term_months;
        $schedule = [];
        $balance = $loan->principal_amount;
        $monthlyRate = $loan->interest_rate / 100 / 12;
        $monthlyPayment = $this->calculateLoanMonthlyPayment(
            $loan->principal_amount,
            $loan->interest_rate,
            $termMonths
        );

        $currentDate = $loan->disbursed_date
            ? Carbon::parse($loan->disbursed_date)->addMonth()
            : now()->addMonth();

        for ($i = 1; $i <= $termMonths; $i++) {
            $interestAmount = $balance * $monthlyRate;
            $principalAmount = $monthlyPayment - $interestAmount;
            $balance -= $principalAmount;

            $schedule[] = [
                'repayment_number' => $i,
                'due_date' => $currentDate->copy(),
                'amount_due' => round($monthlyPayment, 2),
                'principal_amount' => round($principalAmount, 2),
                'interest_amount' => round($interestAmount, 2),
                'balance_after' => round(max(0.0, $balance), 2),
            ];

            $currentDate->addMonth();
        }

        return $schedule;
    }

    /**
     * Calculate overdue penalty for a missed repayment
     */
    public function calculatePenalty(float $amount, int $daysOverdue): float
    {
        $gracePeriod = config('sacco.loans.grace_period_days', 7);

        if ($daysOverdue <= $gracePeriod) {
            return 0.0;
        }

        $penaltyRate = config('sacco.loans.penalty_rate_per_day', 0.1) / 100;
        $maxPenaltyPct = config('sacco.loans.max_penalty_percentage', 10) / 100;

        $daysChargeable = $daysOverdue - $gracePeriod;
        $penalty = $amount * $penaltyRate * $daysChargeable;

        return round(min($penalty, $amount * $maxPenaltyPct), 2);
    }

    /**
     * Calculate fixed-deposit interest (simple interest)
     */
    public function calculateFixedDepositInterest(SaccoAccount $account, int $months): float
    {
        if ($account->account_type !== 'fixed_deposit') {
            return 0.0;
        }

        $rate = $this->getFixedDepositRate($months);
        $interest = ($account->balance * $rate * $months) / 1200;

        return round($interest, 2);
    }

    protected function getFixedDepositRate(int $months): float
    {
        $rates = config('sacco.fixed_deposits.interest_rates', [
            3 => 8.0,
            6 => 10.0,
            12 => 12.0,
            24 => 14.0,
        ]);

        foreach ([24, 12, 6, 3] as $duration) {
            if ($months >= $duration) {
                return (float) ($rates[$duration] ?? 8.0);
            }
        }

        return 8.0;
    }
}
