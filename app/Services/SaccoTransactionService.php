<?php

namespace App\Services;

use App\Models\SaccoMember;
use App\Models\SaccoAccount;
use App\Models\SaccoTransaction;
use App\Models\SaccoAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class SaccoTransactionService
{
    /**
     * Process a deposit transaction
     * 
     * @param SaccoAccount $account
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @return SaccoTransaction
     * @throws Exception
     */
    public function deposit(
        SaccoAccount $account,
        float $amount,
        string $description = 'Deposit',
        array $metadata = []
    ): SaccoTransaction {
        // Validation
        if ($amount <= 0) {
            throw new Exception('Deposit amount must be greater than zero.');
        }

        if (!$account->canDeposit()) {
            throw new Exception('Account cannot accept deposits. Status: ' . $account->status);
        }

        DB::beginTransaction();
        
        try {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;
            
            // Create transaction record
            $transaction = new SaccoTransaction();
            $transaction->account_id = $account->id;
            $transaction->member_id = $account->member_id;
            $transaction->transaction_reference = $this->generateTransactionReference('DEP');
            $transaction->transaction_type = SaccoTransaction::TYPE_DEPOSIT;
            $transaction->description = $description;
            $transaction->notes = $metadata['notes'] ?? null;
            $transaction->transaction_date = now();
            
            // Protected fields - set explicitly
            $transaction->amount = $amount;
            $transaction->balance_before = $balanceBefore;
            $transaction->balance_after = $balanceAfter;
            $transaction->processed_by = auth()->id();
            
            $transaction->save();
            
            // Update account balance
            $account->balance = $balanceAfter;
            $account->available_balance = $balanceAfter;
            $account->save();
            
            // Update member totals
            $this->updateMemberTotals($account->member);
            
            // Log transaction
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_CREATED,
                modelType: SaccoTransaction::class,
                modelId: $transaction->id,
                newValues: $transaction->toArray()
            );
            
            DB::commit();
            
            return $transaction->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process a withdrawal transaction
     * 
     * @param SaccoAccount $account
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @return SaccoTransaction
     * @throws Exception
     */
    public function withdraw(
        SaccoAccount $account,
        float $amount,
        string $description = 'Withdrawal',
        array $metadata = []
    ): SaccoTransaction {
        // Validation
        if ($amount <= 0) {
            throw new Exception('Withdrawal amount must be greater than zero.');
        }

        if (!$account->canWithdraw($amount)) {
            throw new Exception('Insufficient balance or account frozen. Available: UGX ' . number_format($account->available_balance, 2));
        }

        // Check daily withdrawal limit
        $dailyLimit = config('sacco.maximum_withdrawal_daily', 5000000);
        $todayWithdrawals = $account->transactions()
            ->withdrawals()
            ->whereDate('transaction_date', today())
            ->sum('amount');
        
        if (($todayWithdrawals + $amount) > $dailyLimit) {
            throw new Exception('Daily withdrawal limit exceeded. Limit: UGX ' . number_format($dailyLimit, 2));
        }

        DB::beginTransaction();
        
        try {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;
            
            // Create transaction record
            $transaction = new SaccoTransaction();
            $transaction->account_id = $account->id;
            $transaction->member_id = $account->member_id;
            $transaction->transaction_reference = $this->generateTransactionReference('WTH');
            $transaction->transaction_type = SaccoTransaction::TYPE_WITHDRAWAL;
            $transaction->description = $description;
            $transaction->notes = $metadata['notes'] ?? null;
            $transaction->transaction_date = now();
            
            // Protected fields
            $transaction->amount = $amount;
            $transaction->balance_before = $balanceBefore;
            $transaction->balance_after = $balanceAfter;
            $transaction->processed_by = auth()->id();
            
            $transaction->save();
            
            // Update account balance
            $account->balance = $balanceAfter;
            $account->available_balance = $balanceAfter;
            $account->save();
            
            // Update member totals
            $this->updateMemberTotals($account->member);
            
            // Log transaction
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_CREATED,
                modelType: SaccoTransaction::class,
                modelId: $transaction->id,
                newValues: $transaction->toArray()
            );
            
            DB::commit();
            
            return $transaction->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Transfer funds between accounts
     * 
     * @param SaccoAccount $fromAccount
     * @param SaccoAccount $toAccount
     * @param float $amount
     * @param string $description
     * @return array
     * @throws Exception
     */
    public function transfer(
        SaccoAccount $fromAccount,
        SaccoAccount $toAccount,
        float $amount,
        string $description = 'Transfer'
    ): array {
        if ($amount <= 0) {
            throw new Exception('Transfer amount must be greater than zero.');
        }

        if ($fromAccount->id === $toAccount->id) {
            throw new Exception('Cannot transfer to the same account.');
        }

        if (!$fromAccount->canWithdraw($amount)) {
            throw new Exception('Insufficient balance in source account.');
        }

        DB::beginTransaction();
        
        try {
            $transferRef = $this->generateTransactionReference('TRF');
            
            // Withdrawal from source
            $withdrawalTransaction = $this->withdraw(
                $fromAccount,
                $amount,
                "Transfer to {$toAccount->account_number}: {$description}",
                ['transfer_ref' => $transferRef]
            );
            
            // Deposit to destination
            $depositTransaction = $this->deposit(
                $toAccount,
                $amount,
                "Transfer from {$fromAccount->account_number}: {$description}",
                ['transfer_ref' => $transferRef]
            );
            
            DB::commit();
            
            return [
                'withdrawal' => $withdrawalTransaction,
                'deposit' => $depositTransaction,
                'transfer_reference' => $transferRef,
            ];
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Apply interest to account
     * 
     * @param SaccoAccount $account
     * @param int $months
     * @return SaccoTransaction|null
     */
    public function applyInterest(SaccoAccount $account, int $months = 1): ?SaccoTransaction
    {
        $interestAmount = $account->calculateInterest($months);
        
        if ($interestAmount <= 0) {
            return null;
        }

        return $this->deposit(
            $account,
            $interestAmount,
            "Interest for {$months} month(s) at {$account->interest_rate}% p.a.",
            ['type' => 'interest', 'months' => $months]
        );
    }

    /**
     * Get account statement
     * 
     * @param SaccoAccount $account
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getAccountStatement(
        SaccoAccount $account,
        string $startDate,
        string $endDate
    ): array {
        $transactions = $account->transactions()
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->orderBy('transaction_date', 'asc')
            ->get();
        
        $openingBalance = $account->transactions()
            ->where('transaction_date', '<', $startDate)
            ->latest()
            ->value('balance_after') ?? 0;
        
        $closingBalance = $account->balance;
        
        $totalDeposits = $transactions->where('transaction_type', SaccoTransaction::TYPE_DEPOSIT)->sum('amount');
        $totalWithdrawals = $transactions->where('transaction_type', SaccoTransaction::TYPE_WITHDRAWAL)->sum('amount');
        
        return [
            'account' => $account,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'total_deposits' => $totalDeposits,
            'total_withdrawals' => $totalWithdrawals,
            'net_change' => $totalDeposits - $totalWithdrawals,
            'transactions' => $transactions,
            'transaction_count' => $transactions->count(),
        ];
    }

    /**
     * Get transaction summary for member
     * 
     * @param SaccoMember $member
     * @param int $days
     * @return array
     */
    public function getMemberTransactionSummary(SaccoMember $member, int $days = 30): array
    {
        $startDate = now()->subDays($days);
        
        $transactions = $member->transactions()
            ->where('transaction_date', '>=', $startDate)
            ->get();
        
        return [
            'period_days' => $days,
            'total_transactions' => $transactions->count(),
            'total_deposits' => $transactions->where('transaction_type', SaccoTransaction::TYPE_DEPOSIT)->sum('amount'),
            'total_withdrawals' => $transactions->where('transaction_type', SaccoTransaction::TYPE_WITHDRAWAL)->sum('amount'),
            'total_loan_repayments' => $transactions->where('transaction_type', SaccoTransaction::TYPE_LOAN_REPAYMENT)->sum('amount'),
            'average_transaction' => $transactions->avg('amount'),
            'largest_deposit' => $transactions->where('transaction_type', SaccoTransaction::TYPE_DEPOSIT)->max('amount'),
            'largest_withdrawal' => $transactions->where('transaction_type', SaccoTransaction::TYPE_WITHDRAWAL)->max('amount'),
        ];
    }

    /**
     * Update member totals from accounts
     * 
     * @param SaccoMember $member
     * @return void
     */
    private function updateMemberTotals(SaccoMember $member): void
    {
        $sharesBalance = $member->accounts()
            ->where('account_type', SaccoAccount::TYPE_SHARES)
            ->sum('balance');
        
        $savingsBalance = $member->accounts()
            ->where('account_type', SaccoAccount::TYPE_SAVINGS)
            ->sum('balance');
        
        $loansBalance = $member->loans()
            ->active()
            ->sum('balance');
        
        // Update protected fields explicitly
        $member->total_shares = $sharesBalance;
        $member->total_savings = $savingsBalance;
        $member->total_loans = $loansBalance;
        $member->save();
    }

    /**
     * Generate unique transaction reference
     * 
     * @param string $prefix
     * @return string
     */
    private function generateTransactionReference(string $prefix): string
    {
        return $prefix . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
    }

    /**
     * Reverse a transaction (admin only)
     * 
     * @param SaccoTransaction $transaction
     * @param string $reason
     * @return SaccoTransaction
     * @throws Exception
     */
    public function reverseTransaction(SaccoTransaction $transaction, string $reason): SaccoTransaction
    {
        if ($transaction->transaction_date->lt(now()->subDays(7))) {
            throw new Exception('Cannot reverse transactions older than 7 days.');
        }

        DB::beginTransaction();
        
        try {
            $account = $transaction->account;
            
            // Create reversal transaction (opposite type)
            $reversalType = match($transaction->transaction_type) {
                SaccoTransaction::TYPE_DEPOSIT => SaccoTransaction::TYPE_WITHDRAWAL,
                SaccoTransaction::TYPE_WITHDRAWAL => SaccoTransaction::TYPE_DEPOSIT,
                default => SaccoTransaction::TYPE_ADJUSTMENT,
            };
            
            $reversal = new SaccoTransaction();
            $reversal->account_id = $transaction->account_id;
            $reversal->member_id = $transaction->member_id;
            $reversal->transaction_reference = $this->generateTransactionReference('REV');
            $reversal->transaction_type = $reversalType;
            $reversal->description = "Reversal of {$transaction->transaction_reference}: {$reason}";
            $reversal->transaction_date = now();
            
            $balanceBefore = $account->balance;
            $balanceAfter = $reversalType === SaccoTransaction::TYPE_WITHDRAWAL
                ? $balanceBefore - $transaction->amount
                : $balanceBefore + $transaction->amount;
            
            $reversal->amount = $transaction->amount;
            $reversal->balance_before = $balanceBefore;
            $reversal->balance_after = $balanceAfter;
            $reversal->processed_by = auth()->id();
            $reversal->save();
            
            // Update account
            $account->balance = $balanceAfter;
            $account->available_balance = $balanceAfter;
            $account->save();
            
            // Update member totals
            $this->updateMemberTotals($account->member);
            
            // Log reversal
            SaccoAuditLog::log(
                action: 'reversed',
                modelType: SaccoTransaction::class,
                modelId: $reversal->id,
                newValues: ['original_transaction' => $transaction->id, 'reason' => $reason]
            );
            
            DB::commit();
            
            return $reversal->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
