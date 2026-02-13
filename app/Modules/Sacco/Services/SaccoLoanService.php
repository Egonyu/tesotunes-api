<?php

namespace App\Modules\Sacco\Services;

use App\Modules\Sacco\Models\SaccoLoan;
use App\Modules\Sacco\Models\SaccoLoanRepayment;
use App\Modules\Sacco\Models\SaccoMember;
use App\Modules\Sacco\Models\SaccoTransaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaccoLoanService
{
    protected SaccoInterestService $interestService;

    public function __construct(SaccoInterestService $interestService)
    {
        $this->interestService = $interestService;
    }

    /**
     * Check if member is eligible for loan
     */
    public function checkEligibility(SaccoMember $member, float $amount): array
    {
        $eligible = true;
        $reasons = [];
        
        // Check membership status
        if ($member->status !== 'active') {
            $eligible = false;
            $reasons[] = 'Membership must be active';
        }
        
        // Check membership duration
        $monthsActive = $member->approval_date ? $member->approval_date->diffInMonths(now()) : 0;
        $minMonths = config('sacco.loans.min_membership_months', 3);
        if ($monthsActive < $minMonths) {
            $eligible = false;
            $reasons[] = "Minimum {$minMonths} months membership required";
        }
        
        // Check savings balance
        $savingsBalance = $member->accounts()
            ->where('account_type', 'savings')
            ->sum('balance_ugx');
        
        $minSavings = config('sacco.loans.min_savings_balance', 50000);
        if ($savingsBalance < $minSavings) {
            $eligible = false;
            $reasons[] = "Minimum savings of UGX " . number_format($minSavings) . " required";
        }
        
        // Check loan-to-savings ratio
        $maxRatio = config('sacco.loans.max_loan_to_savings_ratio', 3.0);
        $maxLoanAmount = $savingsBalance * $maxRatio;
        if ($amount > $maxLoanAmount) {
            $eligible = false;
            $reasons[] = "Maximum loan amount based on savings: UGX " . number_format($maxLoanAmount);
        }
        
        // Check for existing active loans
        $activeLoans = $member->loans()
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->count();
        
        if ($activeLoans > 0) {
            $eligible = false;
            $reasons[] = 'Cannot have multiple active loans';
        }
        
        // Check credit score
        $minCreditScore = config('sacco.loans.min_credit_score', 400);
        if ($member->credit_score < $minCreditScore) {
            $eligible = false;
            $reasons[] = "Minimum credit score of {$minCreditScore} required";
        }
        
        // Check KYC verification
        if (!$member->kyc_verified) {
            $eligible = false;
            $reasons[] = 'KYC verification required';
        }
        
        return [
            'eligible' => $eligible,
            'reasons' => $reasons,
            'max_amount' => $maxLoanAmount ?? 0,
            'credit_score' => $member->credit_score
        ];
    }

    /**
     * Process loan application
     */
    public function applyForLoan(SaccoMember $member, array $data): SaccoLoan
    {
        $eligibility = $this->checkEligibility($member, $data['principal_amount']);
        
        if (!$eligibility['eligible']) {
            throw new \Exception('Not eligible for loan: ' . implode(', ', $eligibility['reasons']));
        }
        
        DB::beginTransaction();
        try {
            $loanProduct = \App\Modules\Sacco\Models\SaccoLoanProduct::findOrFail($data['loan_product_id']);
            
            // Calculate fees and total amount
            $principal = $data['principal_amount'];
            $processingFee = ($principal * $loanProduct->processing_fee_percentage) / 100;
            $insuranceFee = ($principal * $loanProduct->insurance_fee_percentage) / 100;
            
            $monthlyPayment = $this->interestService->calculateLoanMonthlyPayment(
                $principal,
                $loanProduct->interest_rate,
                $data['repayment_period_months']
            );
            
            $totalAmount = $monthlyPayment * $data['repayment_period_months'];
            
            $loan = SaccoLoan::create([
                'member_id' => $member->id,
                'loan_product_id' => $loanProduct->id,
                'loan_number' => $this->generateLoanNumber(),
                'loan_type' => $loanProduct->code,
                'principal_amount' => $principal,
                'interest_rate' => $loanProduct->interest_rate,
                'processing_fee' => $processingFee,
                'insurance_fee' => $insuranceFee,
                'total_amount' => $totalAmount,
                'repayment_period_months' => $data['repayment_period_months'],
                'monthly_repayment' => $monthlyPayment,
                'outstanding_balance' => $totalAmount,
                'purpose' => $data['purpose'],
                'status' => 'pending',
                'application_date' => now(),
                'auto_deduct_from_royalties' => $data['auto_deduct_from_royalties'] ?? false,
                'royalty_deduction_percentage' => $data['royalty_deduction_percentage'] ?? 30
            ]);
            
            DB::commit();
            return $loan;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Disburse approved loan
     */
    public function disburseLoan(SaccoLoan $loan, array $data): void
    {
        if ($loan->status !== 'approved') {
            throw new \Exception('Loan must be approved before disbursement');
        }
        
        DB::beginTransaction();
        try {
            $loan->update([
                'status' => 'active',
                'disbursed_at' => now(),
                'disbursed_by' => auth()->id(),
                'disbursement_method' => $data['disbursement_method'],
                'disbursement_notes' => $data['disbursement_notes'] ?? null
            ]);
            
            // Create disbursement transaction
            SaccoTransaction::create([
                'member_id' => $loan->member_id,
                'loan_id' => $loan->id,
                'transaction_number' => $this->generateTransactionNumber(),
                'transaction_type' => 'loan_disbursement',
                'amount' => $loan->principal_amount,
                'payment_method' => $data['disbursement_method'],
                'status' => 'completed',
                'description' => "Loan disbursement - {$loan->loan_number}",
                'processed_by' => auth()->id(),
                'processed_at' => now()
            ]);
            
            // Generate repayment schedule
            $this->generateRepaymentSchedule($loan);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Generate repayment schedule for loan
     */
    public function generateRepaymentSchedule(SaccoLoan $loan): void
    {
        $schedule = $this->interestService->generateRepaymentSchedule($loan);
        
        foreach ($schedule as $payment) {
            SaccoLoanRepayment::create([
                'loan_id' => $loan->id,
                'repayment_number' => $payment['repayment_number'],
                'due_date' => $payment['due_date'],
                'amount_due' => $payment['amount_due'],
                'principal_amount' => $payment['principal_amount'],
                'interest_amount' => $payment['interest_amount'],
                'status' => 'pending'
            ]);
        }
        
        $loan->update([
            'first_repayment_date' => $schedule[0]['due_date'],
            'last_repayment_date' => $schedule[count($schedule) - 1]['due_date']
        ]);
    }

    /**
     * Process loan repayment
     */
    public function processRepayment(SaccoLoan $loan, float $amount, array $data): void
    {
        DB::beginTransaction();
        try {
            // Get next pending repayment
            $repayment = $loan->repayments()
                ->where('status', 'pending')
                ->orderBy('due_date')
                ->first();
            
            if (!$repayment) {
                throw new \Exception('No pending repayments found');
            }
            
            // Calculate penalty if overdue
            $penalty = 0;
            if ($repayment->due_date < now()) {
                $daysOverdue = now()->diffInDays($repayment->due_date);
                $penalty = $this->interestService->calculatePenalty($repayment->amount_due, $daysOverdue);
            }
            
            $totalDue = $repayment->amount_due + $penalty;
            
            // Update repayment record
            $repayment->update([
                'amount_paid' => $amount,
                'penalty_amount' => $penalty,
                'payment_date' => now(),
                'payment_method' => $data['payment_method'] ?? 'mobile_money',
                'reference' => $data['reference'] ?? null,
                'status' => $amount >= $totalDue ? 'paid' : 'partial'
            ]);
            
            // Update loan
            $loan->total_repaid += $amount;
            $loan->outstanding_balance -= $amount;
            
            if ($loan->outstanding_balance <= 0) {
                $loan->status = 'completed';
                $loan->fully_repaid_at = now();
            }
            
            $loan->save();
            
            // Create transaction record
            SaccoTransaction::create([
                'member_id' => $loan->member_id,
                'loan_id' => $loan->id,
                'transaction_number' => $this->generateTransactionNumber(),
                'transaction_type' => 'loan_repayment',
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'mobile_money',
                'reference' => $data['reference'] ?? null,
                'status' => 'completed',
                'description' => "Loan repayment - {$loan->loan_number}",
                'processed_at' => now()
            ]);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Check and mark overdue loans
     */
    public function checkOverdueLoans(): int
    {
        $loans = SaccoLoan::where('status', 'active')->get();
        $markedOverdue = 0;
        
        foreach ($loans as $loan) {
            $overdueRepayments = $loan->repayments()
                ->where('status', 'pending')
                ->where('due_date', '<', now())
                ->count();
            
            if ($overdueRepayments > 0 && $loan->status !== 'overdue') {
                $loan->update(['status' => 'overdue']);
                $markedOverdue++;
            }
        }
        
        return $markedOverdue;
    }

    /**
     * Generate loan number
     */
    protected function generateLoanNumber(): string
    {
        return 'LOAN-' . date('Y') . '-' . str_pad(SaccoLoan::count() + 1, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate transaction number
     */
    protected function generateTransactionNumber(): string
    {
        return 'TXN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
}
