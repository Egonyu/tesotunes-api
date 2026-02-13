<?php

namespace App\Services\Sacco;

use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanProduct;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoTransaction;
use App\Models\Sacco\SaccoAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaccoLoanService
{
    protected SaccoAccountService $accountService;

    public function __construct(SaccoAccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    /**
     * Apply for a loan
     *
     * @param SaccoMember $member
     * @param array $loanData [loan_product_id, principal_amount, term_months, purpose, guarantors]
     * @return SaccoLoan
     * @throws \Exception
     */
    public function applyForLoan(SaccoMember $member, array $loanData): SaccoLoan
    {
        // Validate member eligibility
        $eligibility = $this->checkLoanEligibility($member, $loanData['principal_amount']);
        if (!$eligibility['eligible']) {
            throw new \Exception('Loan eligibility check failed: ' . implode(', ', $eligibility['reasons']));
        }

        // Get loan product
        $loanProduct = SaccoLoanProduct::findOrFail($loanData['loan_product_id']);

        // Validate loan amount and term
        if (!$loanProduct->isEligibleAmount($loanData['principal_amount'])) {
            throw new \Exception("Loan amount must be between {$loanProduct->min_amount} and {$loanProduct->max_amount}");
        }

        if (!$loanProduct->isEligibleTerm($loanData['term_months'])) {
            throw new \Exception("Loan term must be between {$loanProduct->min_term_months} and {$loanProduct->max_term_months} months");
        }

        return DB::transaction(function () use ($member, $loanData, $loanProduct) {
            // Calculate loan costs
            $costs = $loanProduct->calculateTotalLoanCost($loanData['principal_amount'], $loanData['term_months']);

            // Create loan application
            $loan = SaccoLoan::create([
                'member_id' => $member->id,
                'loan_product_id' => $loanProduct->id,
                'principal_amount' => $costs['principal_amount'],
                'interest_amount' => $costs['interest_amount'],
                'total_amount' => $costs['total_amount'],
                'processing_fee' => $costs['processing_fee'],
                'insurance_fee' => $costs['insurance_fee'],
                'balance' => $costs['total_amount'],
                'term_months' => $costs['term_months'],
                'monthly_installment' => $costs['monthly_installment'],
                'installments_remaining' => $costs['term_months'],
                'purpose' => $loanData['purpose'],
                'guarantors' => $loanData['guarantors'] ?? null,
                'status' => 'pending_approval',
            ]);

            // Audit log
            SaccoAuditLog::log('loan_applied', $loan, [], $loan->toArray());

            return $loan;
        });
    }

    /**
     * Approve a loan application
     *
     * @param SaccoLoan $loan
     * @param User $admin
     * @return void
     * @throws \Exception
     */
    public function approveLoan(SaccoLoan $loan, User $admin): void
    {
        if ($loan->status !== 'pending_approval') {
            throw new \Exception('Only pending loans can be approved');
        }

        DB::transaction(function () use ($loan, $admin) {
            $oldValues = $loan->toArray();

            $loan->update([
                'status' => 'approved',
                'approved_date' => now(),
                'approved_by' => $admin->id,
            ]);

            // Audit log
            SaccoAuditLog::log('loan_approved', $loan, $oldValues, $loan->fresh()->toArray());
        });
    }

    /**
     * Reject a loan application
     *
     * @param SaccoLoan $loan
     * @param User $admin
     * @param string $reason
     * @return void
     * @throws \Exception
     */
    public function rejectLoan(SaccoLoan $loan, User $admin, string $reason): void
    {
        if ($loan->status !== 'pending_approval') {
            throw new \Exception('Only pending loans can be rejected');
        }

        DB::transaction(function () use ($loan, $admin, $reason) {
            $oldValues = $loan->toArray();

            $loan->update([
                'status' => 'rejected',
            ]);

            // Audit log
            SaccoAuditLog::log('loan_rejected', $loan, $oldValues, [
                'status' => 'rejected',
                'rejected_by' => $admin->id,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Disburse an approved loan
     *
     * @param SaccoLoan $loan
     * @param User $admin
     * @return SaccoTransaction
     * @throws \Exception
     */
    public function disburseLoan(SaccoLoan $loan, User $admin): SaccoTransaction
    {
        if ($loan->status !== 'approved') {
            throw new \Exception('Only approved loans can be disbursed');
        }

        // Get or create member's checking account
        $checkingAccount = $loan->member->checkingAccount;
        if (!$checkingAccount) {
            $checkingAccount = $this->accountService->openAccount($loan->member, 'checking');
        }

        return DB::transaction(function () use ($loan, $checkingAccount, $admin) {
            $oldValues = $loan->toArray();

            // Credit the checking account
            $transaction = $this->accountService->deposit($checkingAccount, $loan->principal_amount, [
                'description' => "Loan Disbursement - {$loan->loan_number}",
                'reference' => $loan->loan_number,
            ]);

            // Deduct processing and insurance fees if any
            $totalFees = $loan->processing_fee + $loan->insurance_fee;
            if ($totalFees > 0) {
                $feeTransaction = SaccoTransaction::create([
                    'account_id' => $checkingAccount->id,
                    'member_id' => $loan->member_id,
                    'transaction_type' => 'fee',
                    'amount' => $totalFees,
                    'balance_before' => $checkingAccount->balance,
                    'balance_after' => $checkingAccount->balance - $totalFees,
                    'description' => "Loan Processing & Insurance Fees - {$loan->loan_number}",
                    'processed_by' => $admin->id,
                ]);

                $checkingAccount->decrement('balance', $totalFees);
                $checkingAccount->decrement('available_balance', $totalFees);
            }

            // Update loan status
            $loan->update([
                'status' => 'disbursed',
                'disbursed_date' => now(),
                'disbursed_by' => $admin->id,
                'due_date' => now()->addMonth(), // First payment due in 1 month
                'maturity_date' => now()->addMonths($loan->term_months),
            ]);

            // Update member's total loans
            $loan->member->increment('total_loans', $loan->total_amount);

            // Audit log
            SaccoAuditLog::log('loan_disbursed', $loan, $oldValues, $loan->fresh()->toArray());

            return $transaction;
        });
    }

    /**
     * Record a loan repayment
     *
     * @param SaccoLoan $loan
     * @param float $amount
     * @param array $metadata [payment_method, reference]
     * @return SaccoTransaction
     * @throws \Exception
     */
    public function recordRepayment(SaccoLoan $loan, float $amount, array $metadata = []): SaccoTransaction
    {
        if (!$loan->is_active) {
            throw new \Exception('Loan must be active to record repayments');
        }

        if ($amount <= 0) {
            throw new \Exception('Repayment amount must be greater than zero');
        }

        if ($amount > $loan->balance) {
            $amount = $loan->balance; // Cap at remaining balance
        }

        // Get member's checking account
        $checkingAccount = $loan->member->checkingAccount;
        if (!$checkingAccount || !$checkingAccount->canWithdraw($amount)) {
            throw new \Exception('Insufficient balance in checking account for repayment');
        }

        return DB::transaction(function () use ($loan, $checkingAccount, $amount, $metadata) {
            // Debit checking account
            $this->accountService->withdraw($checkingAccount, $amount, "Loan Repayment - {$loan->loan_number}");

            // Create loan repayment transaction
            $transaction = SaccoTransaction::create([
                'account_id' => $checkingAccount->id,
                'member_id' => $loan->member_id,
                'transaction_type' => 'loan_repayment',
                'amount' => $amount,
                'balance_before' => $checkingAccount->balance + $amount,
                'balance_after' => $checkingAccount->balance,
                'transaction_reference' => $metadata['reference'] ?? null,
                'description' => "Loan Repayment - {$loan->loan_number}",
                'notes' => $metadata['notes'] ?? null,
                'processed_by' => auth()->id(),
            ]);

            // Update loan
            $loan->recordRepayment($amount);

            // Update member's total loans
            $loan->member->decrement('total_loans', $amount);

            // Audit log
            SaccoAuditLog::log('loan_repayment', $loan, [], [
                'amount' => $amount,
                'balance' => $loan->balance,
                'transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * Calculate loan repayment schedule
     *
     * @param SaccoLoan $loan
     * @return array
     */
    public function calculateLoanSchedule(SaccoLoan $loan): array
    {
        return $loan->calculateRepaymentSchedule();
    }

    /**
     * Check loan eligibility for a member
     *
     * @param SaccoMember $member
     * @param float $amount
     * @return array ['eligible' => bool, 'reasons' => array, 'max_amount' => float]
     */
    public function checkLoanEligibility(SaccoMember $member, float $amount): array
    {
        $reasons = [];
        $eligible = true;

        // Check member status
        if ($member->status !== 'active') {
            $eligible = false;
            $reasons[] = 'Member must be active to apply for loans';
        }

        // Check minimum shares requirement
        if (!$member->hasMinimumShares()) {
            $eligible = false;
            $reasons[] = 'Minimum share capital requirement not met';
        }

        // Check maximum loan limit
        $maxLoan = max($member->total_savings * 3, $member->total_shares * 4);
        if ($amount > $maxLoan) {
            $eligible = false;
            $reasons[] = "Maximum loan amount is UGX " . number_format($maxLoan, 2) . " based on savings/shares";
        }

        // Check active loans count
        if ($member->active_loans_count >= 3) {
            $eligible = false;
            $reasons[] = 'Maximum 3 active loans allowed per member';
        }

        // Check outstanding loan balance
        $outstandingBalance = $member->loans()->whereIn('status', ['active', 'disbursed'])->sum('balance');
        if ($outstandingBalance + $amount > $maxLoan) {
            $eligible = false;
            $reasons[] = 'Total outstanding loan balance would exceed maximum limit';
        }

        // Check for defaulted loans
        $hasDefaultedLoans = $member->loans()->where('status', 'defaulted')->exists();
        if ($hasDefaultedLoans) {
            $eligible = false;
            $reasons[] = 'Member has defaulted loans';
        }

        return [
            'eligible' => $eligible,
            'reasons' => $eligible ? ['All eligibility requirements met'] : $reasons,
            'max_amount' => $maxLoan,
            'current_outstanding' => $outstandingBalance,
            'available_loan_capacity' => $maxLoan - $outstandingBalance,
        ];
    }

    /**
     * Mark overdue loans as defaulted (90+ days)
     *
     * @return int Number of loans marked as defaulted
     */
    public function processDefaultedLoans(): int
    {
        $overdueLoans = SaccoLoan::active()
            ->where('due_date', '<=', now()->subDays(90))
            ->get();

        $defaultedCount = 0;

        foreach ($overdueLoans as $loan) {
            if ($loan->checkDefaultStatus()) {
                $defaultedCount++;
                
                // Notify member (event/notification)
                SaccoAuditLog::log('loan_defaulted', $loan, ['status' => 'active'], ['status' => 'defaulted']);
            }
        }

        return $defaultedCount;
    }

    /**
     * Get loan statistics summary
     *
     * @return array
     */
    public function getLoanSummary(): array
    {
        return [
            'total_loans' => SaccoLoan::count(),
            'pending_approval' => SaccoLoan::pending()->count(),
            'active_loans' => SaccoLoan::active()->count(),
            'completed_loans' => SaccoLoan::completed()->count(),
            'defaulted_loans' => SaccoLoan::defaulted()->count(),
            'total_disbursed_amount' => SaccoLoan::whereIn('status', ['disbursed', 'active', 'completed'])
                ->sum('principal_amount'),
            'total_outstanding_balance' => SaccoLoan::whereIn('status', ['active', 'disbursed'])
                ->sum('balance'),
            'total_repaid_amount' => SaccoLoan::sum('amount_paid'),
            'overdue_loans_count' => SaccoLoan::overdue()->count(),
            'overdue_loans_amount' => SaccoLoan::overdue()->sum('balance'),
        ];
    }

    /**
     * Get loan products with statistics
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLoanProductsWithStats()
    {
        return SaccoLoanProduct::active()
            ->withCount([
                'loans as total_loans',
                'loans as active_loans' => function ($query) {
                    $query->whereIn('status', ['active', 'disbursed']);
                },
            ])
            ->withSum('loans as total_disbursed', 'principal_amount')
            ->get();
    }
}
