<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanRepayment;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use App\Models\Sacco\SaccoShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoAnalyticsController extends Controller
{
    /**
     * GET /api/sacco/analytics/dashboard — overview dashboard
     */
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => [
                'members' => [
                    'total' => SaccoMember::count(),
                    'active' => SaccoMember::where('status', 'active')->count(),
                ],
                'savings' => [
                    'total_balance_ugx' => SaccoSavingsAccount::sum('balance_ugx'),
                    'total_accounts' => SaccoSavingsAccount::where('status', 'active')->count(),
                ],
                'loans' => [
                    'active' => SaccoLoan::whereIn('status', ['disbursed', 'active'])->count(),
                    'outstanding_ugx' => SaccoLoan::whereIn('status', ['disbursed', 'active'])->sum('balance_remaining_ugx'),
                    'pending_applications' => SaccoLoan::where('status', 'pending')->count(),
                ],
                'shares' => [
                    'total_issued' => (int) SaccoShare::sum('total_shares'),
                    'total_value_ugx' => SaccoShare::sum('total_value_ugx'),
                ],
            ],
        ]);
    }

    /**
     * GET /api/sacco/analytics/membership-trends — membership trends (monthly)
     */
    public function membershipTrends(Request $request): JsonResponse
    {
        $months = $request->get('months', 12);

        $trends = SaccoMember::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw('COUNT(*) as registrations')
        )
            ->where('created_at', '>=', now()->subMonths($months))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json(['data' => $trends]);
    }

    /**
     * GET /api/sacco/analytics/loan-performance — loan performance metrics
     */
    public function loanPerformance(): JsonResponse
    {
        $total = SaccoLoan::count();
        $paid = SaccoLoan::where('status', 'paid')->count();
        $active = SaccoLoan::whereIn('status', ['disbursed', 'active'])->count();
        $defaulted = SaccoLoan::where('status', 'defaulted')->count();
        $totalDisbursed = SaccoLoan::sum('principal_amount_ugx');
        $totalCollected = SaccoLoan::sum('amount_paid_ugx');

        return response()->json([
            'data' => [
                'total_loans' => $total,
                'paid' => $paid,
                'active' => $active,
                'defaulted' => $defaulted,
                'repayment_rate' => $total > 0 ? round(($paid / $total) * 100, 2) : 0,
                'collection_rate' => $totalDisbursed > 0 ? round(($totalCollected / $totalDisbursed) * 100, 2) : 0,
                'default_rate' => $total > 0 ? round(($defaulted / $total) * 100, 2) : 0,
            ],
        ]);
    }

    /**
     * GET /api/sacco/analytics/savings — savings analytics
     */
    public function savings(Request $request): JsonResponse
    {
        $months = $request->get('months', 12);

        $monthlyDeposits = SaccoSavingsTransaction::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw("SUM(CASE WHEN type = 'deposit' THEN amount_ugx ELSE 0 END) as deposits"),
            DB::raw("SUM(CASE WHEN type = 'withdrawal' THEN amount_ugx ELSE 0 END) as withdrawals")
        )
            ->where('created_at', '>=', now()->subMonths($months))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json(['data' => $monthlyDeposits]);
    }

    /**
     * GET /api/sacco/analytics/repayments — repayment analytics
     */
    public function repayments(Request $request): JsonResponse
    {
        $months = $request->get('months', 12);

        $monthlyRepayments = SaccoLoanRepayment::select(
            DB::raw("DATE_FORMAT(payment_date, '%Y-%m') as month"),
            DB::raw('COUNT(*) as payments'),
            DB::raw('SUM(amount_ugx) as total_amount_ugx')
        )
            ->where('payment_date', '>=', now()->subMonths($months))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json(['data' => $monthlyRepayments]);
    }

    /**
     * GET /api/sacco/analytics/portfolio — loan portfolio breakdown
     */
    public function portfolio(): JsonResponse
    {
        $byType = SaccoLoan::select('loan_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(principal_amount_ugx) as total_ugx'))
            ->groupBy('loan_type')
            ->get();

        $byStatus = SaccoLoan::select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(balance_remaining_ugx) as outstanding_ugx'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'data' => [
                'by_type' => $byType,
                'by_status' => $byStatus,
            ],
        ]);
    }

    /**
     * GET /api/sacco/analytics/activity — recent SACCO activity
     */
    public function activity(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $since = now()->subDays($days);

        return response()->json([
            'data' => [
                'new_members' => SaccoMember::where('created_at', '>=', $since)->count(),
                'new_accounts' => SaccoSavingsAccount::where('created_at', '>=', $since)->count(),
                'deposits' => SaccoSavingsTransaction::where('type', 'deposit')->where('created_at', '>=', $since)->count(),
                'withdrawals' => SaccoSavingsTransaction::where('type', 'withdrawal')->where('created_at', '>=', $since)->count(),
                'loan_applications' => SaccoLoan::where('created_at', '>=', $since)->count(),
                'loan_disbursements' => SaccoLoan::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $since)->count(),
                'repayments' => SaccoLoanRepayment::where('created_at', '>=', $since)->count(),
                'period_days' => $days,
            ],
        ]);
    }

    /**
     * GET /api/sacco/analytics/top-performers — top saving/share members
     */
    public function topPerformers(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        $topSavers = SaccoSavingsAccount::select('member_id', DB::raw('SUM(balance_ugx) as total_savings_ugx'))
            ->where('status', 'active')
            ->groupBy('member_id')
            ->orderByDesc('total_savings_ugx')
            ->limit($limit)
            ->with('member.user:id,username')
            ->get()
            ->map(fn ($a) => [
                'member_id' => $a->member_id,
                'name' => $a->member->user->username ?? 'N/A',
                'total_savings_ugx' => $a->total_savings_ugx,
            ]);

        $topShareholders = SaccoShare::orderByDesc('total_shares')
            ->limit($limit)
            ->with('member.user:id,username')
            ->get()
            ->map(fn ($s) => [
                'member_id' => $s->member_id,
                'name' => $s->member->user->username ?? 'N/A',
                'total_shares' => $s->total_shares,
                'total_value_ugx' => $s->total_value_ugx,
            ]);

        return response()->json([
            'data' => [
                'top_savers' => $topSavers,
                'top_shareholders' => $topShareholders,
            ],
        ]);
    }

    /**
     * GET /api/sacco/analytics/risk — risk assessment
     */
    public function risk(): JsonResponse
    {
        $totalActive = SaccoLoan::whereIn('status', ['disbursed', 'active'])->count();
        $overdue = SaccoLoan::whereIn('status', ['disbursed', 'active'])
            ->where('maturity_date', '<', now())
            ->where('balance_remaining_ugx', '>', 0)
            ->count();
        $defaulted = SaccoLoan::where('status', 'defaulted')->count();

        $totalOutstanding = SaccoLoan::whereIn('status', ['disbursed', 'active'])->sum('balance_remaining_ugx');
        $overdueAmount = SaccoLoan::whereIn('status', ['disbursed', 'active'])
            ->where('maturity_date', '<', now())
            ->where('balance_remaining_ugx', '>', 0)
            ->sum('balance_remaining_ugx');

        $parRatio = $totalOutstanding > 0 ? round(($overdueAmount / $totalOutstanding) * 100, 2) : 0;

        return response()->json([
            'data' => [
                'active_loans' => $totalActive,
                'overdue_loans' => $overdue,
                'defaulted_loans' => $defaulted,
                'total_outstanding_ugx' => $totalOutstanding,
                'overdue_amount_ugx' => $overdueAmount,
                'portfolio_at_risk_percent' => $parRatio,
                'risk_level' => $parRatio > 10 ? 'high' : ($parRatio > 5 ? 'medium' : 'low'),
            ],
        ]);
    }
}
