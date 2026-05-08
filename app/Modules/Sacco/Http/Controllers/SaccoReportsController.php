<?php

namespace App\Modules\Sacco\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use App\Models\Sacco\SaccoShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaccoReportsController extends Controller
{
    /**
     * GET /api/sacco/reports/membership — membership report
     */
    public function membership(Request $request): JsonResponse
    {
        $total = SaccoMember::count();
        $active = SaccoMember::where('status', 'active')->count();
        $suspended = SaccoMember::where('status', 'suspended')->count();
        $resigned = SaccoMember::where('status', 'resigned')->count();

        $recentJoins = SaccoMember::where('created_at', '>=', now()->subDays(30))->count();

        return response()->json([
            'data' => [
                'total_members' => $total,
                'active' => $active,
                'suspended' => $suspended,
                'resigned' => $resigned,
                'new_last_30_days' => $recentJoins,
                'status_breakdown' => compact('active', 'suspended', 'resigned'),
            ],
        ]);
    }

    /**
     * GET /api/sacco/reports/loans — loans report
     */
    public function loans(Request $request): JsonResponse
    {
        $totalLoans = SaccoLoan::count();
        $activeLoans = SaccoLoan::whereIn('status', ['disbursed', 'active'])->count();
        $totalDisbursed = SaccoLoan::whereNotNull('disbursement_date')->sum('principal_amount_ugx');
        $totalRepaid = SaccoLoan::sum('amount_paid_ugx');
        $totalOutstanding = SaccoLoan::whereIn('status', ['disbursed', 'active'])->sum('balance_remaining_ugx');
        $defaulted = SaccoLoan::where('status', 'defaulted')->count();

        return response()->json([
            'data' => [
                'total_loans' => $totalLoans,
                'active_loans' => $activeLoans,
                'defaulted_loans' => $defaulted,
                'total_disbursed_ugx' => $totalDisbursed,
                'total_repaid_ugx' => $totalRepaid,
                'total_outstanding_ugx' => $totalOutstanding,
                'recovery_rate' => $totalDisbursed > 0 ? round(($totalRepaid / $totalDisbursed) * 100, 2) : 0,
            ],
        ]);
    }

    /**
     * GET /api/sacco/reports/savings — savings report
     */
    public function savings(Request $request): JsonResponse
    {
        $totalAccounts = SaccoSavingsAccount::count();
        $activeAccounts = SaccoSavingsAccount::where('status', 'active')->count();
        $totalBalance = SaccoSavingsAccount::sum('balance_ugx');
        $totalDeposits = SaccoSavingsTransaction::where('type', 'deposit')->sum('amount_ugx');
        $totalWithdrawals = SaccoSavingsTransaction::where('type', 'withdrawal')->sum('amount_ugx');

        return response()->json([
            'data' => [
                'total_accounts' => $totalAccounts,
                'active_accounts' => $activeAccounts,
                'total_balance_ugx' => $totalBalance,
                'total_deposits_ugx' => $totalDeposits,
                'total_withdrawals_ugx' => $totalWithdrawals,
                'average_balance_ugx' => $activeAccounts > 0 ? round($totalBalance / $activeAccounts) : 0,
            ],
        ]);
    }

    /**
     * GET /api/sacco/reports/shares — shares report
     */
    public function shares(Request $request): JsonResponse
    {
        $totalShareholders = SaccoShare::where('total_shares', '>', 0)->count();
        $totalSharesIssued = SaccoShare::sum('total_shares');
        $totalShareValue = SaccoShare::sum('total_value_ugx');
        $pricePerShare = (int) config('sacco.share_capital.share_value', 10000);

        return response()->json([
            'data' => [
                'total_shareholders' => $totalShareholders,
                'total_shares_issued' => (int) $totalSharesIssued,
                'total_share_value_ugx' => $totalShareValue,
                'price_per_share_ugx' => $pricePerShare,
                'average_shares_per_member' => $totalShareholders > 0 ? round($totalSharesIssued / $totalShareholders, 1) : 0,
            ],
        ]);
    }

    /**
     * GET /api/sacco/reports/financial — consolidated financial report
     */
    public function financial(Request $request): JsonResponse
    {
        $totalSavings = SaccoSavingsAccount::sum('balance_ugx');
        $totalLoansOutstanding = SaccoLoan::whereIn('status', ['disbursed', 'active'])->sum('balance_remaining_ugx');
        $totalShareCapital = SaccoShare::sum('total_value_ugx');
        $totalInterestEarned = SaccoLoan::sum('amount_paid_ugx') - SaccoLoan::sum('principal_amount_ugx');
        $totalInterestEarned = max(0, $totalInterestEarned);

        return response()->json([
            'data' => [
                'total_savings_ugx' => $totalSavings,
                'total_loans_outstanding_ugx' => $totalLoansOutstanding,
                'total_share_capital_ugx' => $totalShareCapital,
                'total_interest_earned_ugx' => $totalInterestEarned,
                'total_assets_ugx' => $totalLoansOutstanding + $totalSavings,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/sacco/reports/member-statement/{member} — individual member statement
     */
    public function memberStatement(Request $request, $member): JsonResponse
    {
        $saccoMember = SaccoMember::with('user:id,username,email')->findOrFail($member);

        $savingsAccounts = SaccoSavingsAccount::where('member_id', $member)->get(['id', 'account_number', 'account_type', 'balance_ugx', 'status']);
        $loans = SaccoLoan::where('member_id', $member)->get(['id', 'loan_number', 'loan_type', 'principal_amount_ugx', 'balance_remaining_ugx', 'status']);
        $shares = SaccoShare::where('member_id', $member)->first();

        $recentTransactions = SaccoSavingsTransaction::where('member_id', $member)
            ->latest()
            ->take(20)
            ->get(['type', 'amount_ugx', 'balance_after_ugx', 'description', 'created_at']);

        return response()->json([
            'data' => [
                'member' => [
                    'id' => $saccoMember->id,
                    'member_number' => $saccoMember->member_number,
                    'name' => $saccoMember->user->username ?? null,
                ],
                'savings_accounts' => $savingsAccounts,
                'loans' => $loans,
                'shares' => $shares ? [
                    'total_shares' => $shares->total_shares,
                    'total_value_ugx' => $shares->total_value_ugx,
                ] : null,
                'recent_transactions' => $recentTransactions,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/sacco/reports/overdue — overdue loans
     */
    public function overdue(Request $request): JsonResponse
    {
        $overdueLoans = SaccoLoan::whereIn('status', ['disbursed', 'active'])
            ->where('maturity_date', '<', now())
            ->where('balance_remaining_ugx', '>', 0)
            ->with('member.user:id,username')
            ->get()
            ->map(fn ($loan) => [
                'loan_id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'member' => $loan->member->user->username ?? 'N/A',
                'principal_ugx' => $loan->principal_amount_ugx,
                'balance_remaining_ugx' => $loan->balance_remaining_ugx,
                'maturity_date' => $loan->maturity_date,
                'days_overdue' => now()->diffInDays($loan->maturity_date),
            ]);

        return response()->json([
            'data' => [
                'total_overdue' => $overdueLoans->count(),
                'total_overdue_amount_ugx' => $overdueLoans->sum('balance_remaining_ugx'),
                'overdue_loans' => $overdueLoans,
            ],
        ]);
    }
}
