<?php

namespace App\Services\Sacco;

use App\Models\Sacco\SaccoAccount;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoTransaction;
use App\Models\Sacco\SaccoAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaccoAccountService
{
    /**
     * Open a new account for a member
     *
     * @param SaccoMember $member
     * @param string $accountType
     * @param array $data
     * @return SaccoAccount
     * @throws \Exception
     */
    public function openAccount(SaccoMember $member, string $accountType, array $data = []): SaccoAccount
    {
        // Validate member status
        if ($member->status !== 'active') {
            throw new \Exception('Member must be active to open new accounts');
        }

        // Check if account type already exists
        $existingAccount = $member->accounts()->where('account_type', $accountType)->first();
        if ($existingAccount) {
            throw new \Exception("Member already has a {$accountType} account");
        }

        return DB::transaction(function () use ($member, $accountType, $data) {
            $account = SaccoAccount::create([
                'member_id' => $member->id,
                'account_type' => $accountType,
                'interest_rate' => $data['interest_rate'] ?? 0,
                'status' => 'active',
            ]);

            // Audit log
            SaccoAuditLog::log('account_opened', $account, [], $account->toArray());

            return $account;
        });
    }

    /**
     * Deposit funds into an account
     *
     * @param SaccoAccount $account
     * @param float $amount
     * @param array $metadata [description, payment_method, reference]
     * @return SaccoTransaction
     * @throws \Exception
     */
    public function deposit(SaccoAccount $account, float $amount, array $metadata = []): SaccoTransaction
    {
        // Validation
        if ($amount <= 0) {
            throw new \Exception('Deposit amount must be greater than zero');
        }

        if ($account->status !== 'active') {
            throw new \Exception('Account must be active to accept deposits');
        }

        return DB::transaction(function () use ($account, $amount, $metadata) {
            // Lock account for update
            $account = SaccoAccount::lockForUpdate()->find($account->id);

            // Create transaction record
            $transaction = SaccoTransaction::create([
                'account_id' => $account->id,
                'member_id' => $account->member_id,
                'transaction_type' => 'deposit',
                'amount' => $amount,
                'balance_before' => $account->balance,
                'balance_after' => $account->balance + $amount,
                'transaction_reference' => $metadata['reference'] ?? null,
                'description' => $metadata['description'] ?? 'Deposit',
                'notes' => $metadata['notes'] ?? null,
                'processed_by' => auth()->id(),
            ]);

            // Update account balance
            $account->increment('balance', $amount);
            $account->increment('available_balance', $amount);

            // Update member aggregate based on account type
            if ($account->account_type === 'savings') {
                $account->member->increment('total_savings', $amount);
            } elseif ($account->account_type === 'shares') {
                $account->member->increment('total_shares', $amount);
            }

            // Audit log
            SaccoAuditLog::log('deposit', $transaction, [], $transaction->toArray());

            return $transaction;
        });
    }

    /**
     * Withdraw funds from an account
     *
     * @param SaccoAccount $account
     * @param float $amount
     * @param string $description
     * @return SaccoTransaction
     * @throws \Exception
     */
    public function withdraw(SaccoAccount $account, float $amount, string $description): SaccoTransaction
    {
        // Validation
        if ($amount <= 0) {
            throw new \Exception('Withdrawal amount must be greater than zero');
        }

        if (!$account->canWithdraw($amount)) {
            throw new \Exception('Insufficient available balance for withdrawal');
        }

        return DB::transaction(function () use ($account, $amount, $description) {
            // Lock account for update
            $account = SaccoAccount::lockForUpdate()->find($account->id);

            // Create transaction record
            $transaction = SaccoTransaction::create([
                'account_id' => $account->id,
                'member_id' => $account->member_id,
                'transaction_type' => 'withdrawal',
                'amount' => $amount,
                'balance_before' => $account->balance,
                'balance_after' => $account->balance - $amount,
                'description' => $description,
                'processed_by' => auth()->id(),
            ]);

            // Update account balance
            $account->decrement('balance', $amount);
            $account->decrement('available_balance', $amount);

            // Update member aggregate
            if ($account->account_type === 'savings') {
                $account->member->decrement('total_savings', $amount);
            } elseif ($account->account_type === 'shares') {
                $account->member->decrement('total_shares', $amount);
            }

            // Audit log
            SaccoAuditLog::log('withdrawal', $transaction, [], $transaction->toArray());

            return $transaction;
        });
    }

    /**
     * Transfer funds between accounts
     *
     * @param SaccoAccount $fromAccount
     * @param SaccoAccount $toAccount
     * @param float $amount
     * @param string $description
     * @return array [from_transaction, to_transaction]
     * @throws \Exception
     */
    public function transfer(SaccoAccount $fromAccount, SaccoAccount $toAccount, float $amount, string $description = 'Internal Transfer'): array
    {
        // Validation
        if ($amount <= 0) {
            throw new \Exception('Transfer amount must be greater than zero');
        }

        if (!$fromAccount->canWithdraw($amount)) {
            throw new \Exception('Insufficient balance in source account');
        }

        if ($toAccount->status !== 'active') {
            throw new \Exception('Destination account must be active');
        }

        return DB::transaction(function () use ($fromAccount, $toAccount, $amount, $description) {
            $transferReference = 'TRF-' . Str::upper(Str::random(10));

            // Debit source account
            $fromAccount = SaccoAccount::lockForUpdate()->find($fromAccount->id);
            $fromTransaction = SaccoTransaction::create([
                'account_id' => $fromAccount->id,
                'member_id' => $fromAccount->member_id,
                'transaction_type' => 'transfer',
                'amount' => $amount,
                'balance_before' => $fromAccount->balance,
                'balance_after' => $fromAccount->balance - $amount,
                'transaction_reference' => $transferReference,
                'description' => $description . ' (Debit)',
                'processed_by' => auth()->id(),
            ]);

            $fromAccount->decrement('balance', $amount);
            $fromAccount->decrement('available_balance', $amount);

            // Credit destination account
            $toAccount = SaccoAccount::lockForUpdate()->find($toAccount->id);
            $toTransaction = SaccoTransaction::create([
                'account_id' => $toAccount->id,
                'member_id' => $toAccount->member_id,
                'transaction_type' => 'transfer',
                'amount' => $amount,
                'balance_before' => $toAccount->balance,
                'balance_after' => $toAccount->balance + $amount,
                'transaction_reference' => $transferReference,
                'description' => $description . ' (Credit)',
                'processed_by' => auth()->id(),
            ]);

            $toAccount->increment('balance', $amount);
            $toAccount->increment('available_balance', $amount);

            // Audit log
            SaccoAuditLog::log('transfer', $fromTransaction, [], [
                'from_transaction' => $fromTransaction->toArray(),
                'to_transaction' => $toTransaction->toArray(),
            ]);

            return [
                'from_transaction' => $fromTransaction,
                'to_transaction' => $toTransaction,
            ];
        });
    }

    /**
     * Calculate and credit interest to account
     *
     * @param SaccoAccount $account
     * @return SaccoTransaction|null
     */
    public function calculateInterest(SaccoAccount $account): ?SaccoTransaction
    {
        $interestAmount = $account->calculateMonthlyInterest();

        if ($interestAmount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($account, $interestAmount) {
            $account = SaccoAccount::lockForUpdate()->find($account->id);

            $transaction = SaccoTransaction::create([
                'account_id' => $account->id,
                'member_id' => $account->member_id,
                'transaction_type' => 'interest',
                'amount' => $interestAmount,
                'balance_before' => $account->balance,
                'balance_after' => $account->balance + $interestAmount,
                'description' => 'Monthly Interest Credit',
                'processed_by' => null, // System generated
            ]);

            $account->increment('balance', $interestAmount);
            $account->increment('available_balance', $interestAmount);

            if ($account->account_type === 'savings') {
                $account->member->increment('total_savings', $interestAmount);
            }

            return $transaction;
        });
    }

    /**
     * Freeze account (prevent transactions)
     *
     * @param SaccoAccount $account
     * @param string $reason
     * @return void
     */
    public function freezeAccount(SaccoAccount $account, string $reason): void
    {
        DB::transaction(function () use ($account, $reason) {
            $oldStatus = $account->status;
            $account->update(['status' => 'frozen']);

            SaccoAuditLog::log('account_frozen', $account, ['status' => $oldStatus], [
                'status' => 'frozen',
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Unfreeze account
     *
     * @param SaccoAccount $account
     * @return void
     */
    public function unfreezeAccount(SaccoAccount $account): void
    {
        if ($account->status !== 'frozen') {
            throw new \Exception('Only frozen accounts can be unfrozen');
        }

        DB::transaction(function () use ($account) {
            $oldStatus = $account->status;
            $account->update(['status' => 'active']);

            SaccoAuditLog::log('account_unfrozen', $account, ['status' => $oldStatus], ['status' => 'active']);
        });
    }

    /**
     * Close account
     *
     * @param SaccoAccount $account
     * @return void
     * @throws \Exception
     */
    public function closeAccount(SaccoAccount $account): void
    {
        if ($account->balance > 0) {
            throw new \Exception('Account must have zero balance before closing');
        }

        DB::transaction(function () use ($account) {
            $account->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            SaccoAuditLog::log('account_closed', $account, [], $account->fresh()->toArray());
        });
    }

    /**
     * Get account transaction history
     *
     * @param SaccoAccount $account
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTransactionHistory(SaccoAccount $account, int $limit = 50)
    {
        return $account->transactions()
            ->with('processor')
            ->orderBy('transaction_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get account statement for date range
     *
     * @param SaccoAccount $account
     * @param \Carbon\Carbon $startDate
     * @param \Carbon\Carbon $endDate
     * @return array
     */
    public function getAccountStatement(SaccoAccount $account, $startDate, $endDate): array
    {
        $transactions = $account->transactions()
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->orderBy('transaction_date', 'asc')
            ->get();

        $openingBalance = $account->transactions()
            ->where('transaction_date', '<', $startDate)
            ->orderBy('transaction_date', 'desc')
            ->value('balance_after') ?? 0;

        $totalDebits = $transactions->where('is_debit', true)->sum('amount');
        $totalCredits = $transactions->where('is_credit', true)->sum('amount');

        return [
            'account' => $account,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $account->balance,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'transaction_count' => $transactions->count(),
            'transactions' => $transactions,
        ];
    }
}
