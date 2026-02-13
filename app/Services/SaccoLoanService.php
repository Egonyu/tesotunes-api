<?php

namespace App\Services;

use App\Models\SaccoMember;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoAccount;
use App\Models\SaccoTransaction;
use App\Models\SaccoAuditLog;
use App\Notifications\LoanStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class SaccoLoanService
{
    protected SaccoTransactionService $transactionService;

    public function __construct(SaccoTransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Apply for a loan
     * 
     * @param SaccoMember $member
     * @param SaccoLoanProduct $loanProduct
     * @param array $data
     * @return SaccoLoan
     * @throws Exception
     */
    public function applyForLoan(
        SaccoMember $member,
        SaccoLoanProduct $loanProduct,
        array $data
    ): SaccoLoan {
        // Validation
        if (!$member->canApplyForLoan()) {
            throw new Exception('Member does not meet loan eligibility requirements. Minimum shares: UGX ' . number_format(config('sacco.minimum_shares_for_loan', 100000), 2));
        }

        if (!$loanProduct->is_active) {
            throw new Exception('This loan product is currently not available.');
        }

        $requestedAmount = $data['principal_amount'];
        $termMonths = $data['term_months'];

        if (!$loanProduct->isAmountEligible($requestedAmount)) {
            throw new Exception("Loan amount must be between UGX {$loanProduct->min_amount} and UGX {$loanProduct->max_amount}");
        }

        if (!$loanProduct->isTermEligible($termMonths)) {
            throw new Exception("Loan term must be between {$loanProduct->min_term_months} and {$loanProduct->max_term_months} months");
        }

        $eligibleAmount = $member->calculateLoanEligibility();
        if ($requestedAmount > $eligibleAmount) {
            throw new Exception("Requested amount exceeds loan eligibility. Maximum eligible: UGX " . number_format($eligibleAmount, 2));
        }

        // Check for active loans
        $activeLoanCount = $member->loans()->active()->count();
        $maxLoans = config('sacco.max_active_loans_per_member', 2);
        if ($activeLoanCount >= $maxLoans) {
            throw new Exception("Member already has {$activeLoanCount} active loan(s). Maximum allowed: {$maxLoans}");
        }

        DB::beginTransaction();
        
        try {
            // Calculate loan details
            $interestAmount = $loanProduct->calculateTotalInterest($requestedAmount, $termMonths);
            $processingFee = $loanProduct->calculateProcessingFee($requestedAmount);
            $insuranceFee = $loanProduct->calculateInsuranceFee($requestedAmount);
            $totalAmount = $requestedAmount + $interestAmount + $processingFee + $insuranceFee;
            $monthlyInstallment = $totalAmount / $termMonths;
            
            // Create loan application
            $loan = new SaccoLoan();
            $loan->member_id = $member->id;
            $loan->loan_product_id = $loanProduct->id;
            $loan->loan_number = $this->generateLoanNumber();
            $loan->term_months = $termMonths;
            $loan->purpose = $data['purpose'] ?? null;
            $loan->guarantors = $data['guarantors'] ?? [];
            $loan->applied_date = now();
            
            // Protected fields - set explicitly
            $loan->principal_amount = $requestedAmount;
            $loan->interest_amount = $interestAmount;
            $loan->total_amount = $totalAmount;
            $loan->processing_fee = $processingFee;
            $loan->insurance_fee = $insuranceFee;
            $loan->amount_paid = 0;
            $loan->balance = $totalAmount;
            $loan->monthly_installment = $monthlyInstallment;
            $loan->installments_paid = 0;
            $loan->installments_remaining = $termMonths;
            $loan->status = SaccoLoan::STATUS_PENDING;
            
            $loan->save();
            
            // Log application
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_CREATED,
                modelType: SaccoLoan::class,
                modelId: $loan->id,
                newValues: $loan->toArray()
            );
            
            DB::commit();
            
            return $loan->fresh(['loanProduct', 'member']);
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Approve a loan application
     * 
     * @param SaccoLoan $loan
     * @param array $data
     * @return SaccoLoan
     * @throws Exception
     */
    public function approveLoan(SaccoLoan $loan, array $data = []): SaccoLoan
    {
        if ($loan->status !== SaccoLoan::STATUS_PENDING) {
            throw new Exception('Only pending loans can be approved. Current status: ' . $loan->status);
        }

        DB::beginTransaction();
        
        try {
            $oldValues = $loan->toArray();
            
            // Update loan status
            $loan->status = SaccoLoan::STATUS_APPROVED;
            $loan->approved_date = now();
            $loan->approved_by = auth()->id();
            $loan->due_date = now()->addMonth(); // First installment due in 1 month
            $loan->maturity_date = now()->addMonths($loan->term_months);
            $loan->save();
            
            // Log approval
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_APPROVED,
                modelType: SaccoLoan::class,
                modelId: $loan->id,
                oldValues: $oldValues,
                newValues: $loan->toArray()
            );
            
            DB::commit();
            
            // Send approval notification
            if ($loan->member && $loan->member->user) {
                $loan->member->user->notify(new LoanStatusNotification($loan, 'approved'));
            }
            
            return $loan->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Disburse an approved loan
     * 
     * @param SaccoLoan $loan
     * @param string $disbursementMethod
     * @return SaccoLoan
     * @throws Exception
     */
    public function disburseLoan(SaccoLoan $loan, string $disbursementMethod = 'account'): SaccoLoan
    {
        if ($loan->status !== SaccoLoan::STATUS_APPROVED) {
            throw new Exception('Only approved loans can be disbursed. Current status: ' . $loan->status);
        }

        DB::beginTransaction();
        
        try {
            $member = $loan->member;
            
            // Get member's savings account for disbursement
            $savingsAccount = $member->accounts()
                ->where('account_type', SaccoAccount::TYPE_SAVINGS)
                ->active()
                ->first();
            
            if (!$savingsAccount && $disbursementMethod === 'account') {
                throw new Exception('No active savings account found for loan disbursement.');
            }
            
            $oldValues = $loan->toArray();
            
            // Update loan status
            $loan->status = SaccoLoan::STATUS_DISBURSED;
            $loan->disbursed_date = now();
            $loan->disbursed_by = auth()->id();
            $loan->save();
            
            // Credit loan amount to savings account (minus fees)
            $netDisbursement = $loan->principal_amount - $loan->processing_fee - $loan->insurance_fee;
            
            if ($disbursementMethod === 'account') {
                $this->transactionService->deposit(
                    $savingsAccount,
                    $netDisbursement,
                    "Loan disbursement: {$loan->loan_number}",
                    ['loan_id' => $loan->id]
                );
            }
            
            // Create loan disbursement transaction record
            $transaction = new SaccoTransaction();
            $transaction->account_id = $savingsAccount ? $savingsAccount->id : null;
            $transaction->member_id = $member->id;
            $transaction->transaction_reference = 'LOAN-' . $loan->loan_number;
            $transaction->transaction_type = SaccoTransaction::TYPE_LOAN_DISBURSEMENT;
            $transaction->description = "Loan disbursement: {$loan->loan_number}";
            $transaction->amount = $netDisbursement;
            $transaction->balance_before = $savingsAccount ? $savingsAccount->balance - $netDisbursement : 0;
            $transaction->balance_after = $savingsAccount ? $savingsAccount->balance : 0;
            $transaction->processed_by = auth()->id();
            $transaction->transaction_date = now();
            $transaction->save();
            
            // Update member total loans
            $member->total_loans = $member->loans()->active()->sum('balance');
            $member->save();
            
            // Log disbursement
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_DISBURSED,
                modelType: SaccoLoan::class,
                modelId: $loan->id,
                oldValues: $oldValues,
                newValues: array_merge($loan->toArray(), [
                    'net_disbursement' => $netDisbursement,
                    'disbursement_method' => $disbursementMethod
                ])
            );
            
            DB::commit();
            
            return $loan->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Make a loan repayment
     * 
     * @param SaccoLoan $loan
     * @param float $amount
     * @param array $metadata
     * @return SaccoTransaction
     * @throws Exception
     */
    public function makeRepayment(SaccoLoan $loan, float $amount, array $metadata = []): SaccoTransaction
    {
        if (!$loan->canRepay()) {
            throw new Exception('This loan cannot accept repayments. Status: ' . $loan->status);
        }

        if ($amount <= 0) {
            throw new Exception('Repayment amount must be greater than zero.');
        }

        if ($amount > $loan->balance) {
            throw new Exception('Repayment amount cannot exceed loan balance. Balance: UGX ' . number_format($loan->balance, 2));
        }

        DB::beginTransaction();
        
        try {
            $member = $loan->member;
            $oldBalance = $loan->balance;
            
            // Calculate late fee if overdue
            $lateFee = $loan->isOverdue() ? $loan->calculateLateFee() : 0;
            $effectivePayment = $amount - $lateFee;
            
            // Update loan amounts
            $loan->amount_paid += $effectivePayment;
            $loan->balance = $loan->total_amount - $loan->amount_paid;
            
            // Calculate installments paid
            $installmentsPaid = floor($loan->amount_paid / $loan->monthly_installment);
            $loan->installments_paid = $installmentsPaid;
            $loan->installments_remaining = $loan->term_months - $installmentsPaid;
            
            // Update due date
            if ($loan->installments_remaining > 0) {
                $loan->due_date = now()->addMonth();
            }
            
            // Check if fully paid
            if ($loan->balance <= 0) {
                $loan->status = SaccoLoan::STATUS_COMPLETED;
                $loan->balance = 0;
            }
            
            $loan->save();
            
            // Create repayment transaction
            $transaction = new SaccoTransaction();
            $transaction->member_id = $member->id;
            $transaction->transaction_reference = 'REP-' . $loan->loan_number . '-' . now()->format('YmdHis');
            $transaction->transaction_type = SaccoTransaction::TYPE_LOAN_REPAYMENT;
            $transaction->description = "Loan repayment: {$loan->loan_number}";
            $transaction->notes = $metadata['notes'] ?? null;
            $transaction->amount = $amount;
            $transaction->balance_before = $oldBalance;
            $transaction->balance_after = $loan->balance;
            $transaction->processed_by = auth()->id();
            $transaction->transaction_date = now();
            $transaction->save();
            
            // Update member total loans
            $member->total_loans = $member->loans()->active()->sum('balance');
            $member->save();
            
            // Log repayment
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_REPAID,
                modelType: SaccoLoan::class,
                modelId: $loan->id,
                newValues: [
                    'amount_paid' => $amount,
                    'late_fee' => $lateFee,
                    'new_balance' => $loan->balance,
                    'status' => $loan->status,
                ]
            );
            
            DB::commit();
            
            return $transaction->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reject a loan application
     * 
     * @param SaccoLoan $loan
     * @param string $reason
     * @return SaccoLoan
     */
    public function rejectLoan(SaccoLoan $loan, string $reason): SaccoLoan
    {
        if ($loan->status !== SaccoLoan::STATUS_PENDING) {
            throw new Exception('Only pending loans can be rejected.');
        }

        DB::beginTransaction();
        
        try {
            $oldValues = $loan->toArray();
            
            $loan->status = SaccoLoan::STATUS_REJECTED;
            $loan->save();
            
            SaccoAuditLog::log(
                action: SaccoAuditLog::ACTION_REJECTED,
                modelType: SaccoLoan::class,
                modelId: $loan->id,
                oldValues: $oldValues,
                newValues: array_merge($loan->toArray(), ['rejection_reason' => $reason])
            );
            
            DB::commit();
            
            return $loan->fresh();
            
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get loan statistics for a member
     * 
     * @param SaccoMember $member
     * @return array
     */
    public function getMemberLoanStatistics(SaccoMember $member): array
    {
        $activeLoans = $member->loans()->active()->get();
        $completedLoans = $member->loans()->where('status', SaccoLoan::STATUS_COMPLETED)->count();
        $defaultedLoans = $member->loans()->where('status', SaccoLoan::STATUS_DEFAULTED)->count();
        
        return [
            'active_loans_count' => $activeLoans->count(),
            'total_active_balance' => $activeLoans->sum('balance'),
            'total_borrowed_lifetime' => $member->loans()->sum('principal_amount'),
            'total_repaid_lifetime' => $member->loans()->sum('amount_paid'),
            'completed_loans_count' => $completedLoans,
            'defaulted_loans_count' => $defaultedLoans,
            'current_eligibility' => $member->calculateLoanEligibility(),
            'can_apply' => $member->canApplyForLoan(),
            'overdue_loans' => $member->loans()->overdue()->count(),
        ];
    }

    /**
     * Generate unique loan number
     * 
     * @return string
     */
    private function generateLoanNumber(): string
    {
        $prefix = config('sacco.loan_number_prefix', 'LN');
        $year = now()->format('y');
        
        $lastLoan = SaccoLoan::where('loan_number', 'like', "{$prefix}{$year}%")
            ->orderBy('loan_number', 'desc')
            ->first();
        
        if ($lastLoan) {
            $lastNumber = (int) substr($lastLoan->loan_number, -5);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }
        
        return $prefix . $year . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get portfolio summary (admin view)
     * 
     * @return array
     */
    public function getPortfolioSummary(): array
    {
        return [
            'total_loans' => SaccoLoan::count(),
            'active_loans' => SaccoLoan::active()->count(),
            'pending_approvals' => SaccoLoan::pending()->count(),
            'total_disbursed' => SaccoLoan::whereIn('status', [SaccoLoan::STATUS_DISBURSED, SaccoLoan::STATUS_ACTIVE, SaccoLoan::STATUS_COMPLETED])->sum('principal_amount'),
            'outstanding_balance' => SaccoLoan::active()->sum('balance'),
            'total_repaid' => SaccoLoan::sum('amount_paid'),
            'overdue_loans' => SaccoLoan::overdue()->count(),
            'overdue_amount' => SaccoLoan::overdue()->sum('balance'),
            'default_rate' => $this->calculateDefaultRate(),
            'repayment_rate' => $this->calculateRepaymentRate(),
        ];
    }

    /**
     * Calculate default rate percentage
     * 
     * @return float
     */
    private function calculateDefaultRate(): float
    {
        $totalLoans = SaccoLoan::whereIn('status', [
            SaccoLoan::STATUS_ACTIVE,
            SaccoLoan::STATUS_COMPLETED,
            SaccoLoan::STATUS_DEFAULTED
        ])->count();
        
        if ($totalLoans === 0) {
            return 0;
        }
        
        $defaultedLoans = SaccoLoan::where('status', SaccoLoan::STATUS_DEFAULTED)->count();
        
        return ($defaultedLoans / $totalLoans) * 100;
    }

    /**
     * Calculate repayment rate percentage
     * 
     * @return float
     */
    private function calculateRepaymentRate(): float
    {
        $totalDisbursed = SaccoLoan::whereIn('status', [
            SaccoLoan::STATUS_DISBURSED,
            SaccoLoan::STATUS_ACTIVE,
            SaccoLoan::STATUS_COMPLETED
        ])->sum('total_amount');
        
        if ($totalDisbursed === 0) {
            return 0;
        }
        
        $totalRepaid = SaccoLoan::whereIn('status', [
            SaccoLoan::STATUS_DISBURSED,
            SaccoLoan::STATUS_ACTIVE,
            SaccoLoan::STATUS_COMPLETED
        ])->sum('amount_paid');
        
        return ($totalRepaid / $totalDisbursed) * 100;
    }
}
