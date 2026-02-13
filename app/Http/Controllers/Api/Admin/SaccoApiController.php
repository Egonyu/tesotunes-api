<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoApiController extends Controller
{
    /**
     * Get SACCO statistics.
     */
    public function stats()
    {
        $totalMembers = DB::table('sacco_members')->where('status', 'active')->count();
        $totalLoans = DB::table('sacco_loans')->count();
        $activeLoans = DB::table('sacco_loans')
            ->whereIn('status', ['active', 'disbursed'])
            ->count();
        $pendingLoans = DB::table('sacco_loans')->where('status', 'pending')->count();

        $totalSavings = DB::table('sacco_savings_accounts')
            ->sum('balance_ugx') ?? 0;

        $totalLoansAmount = DB::table('sacco_loans')
            ->where('status', 'active')
            ->sum('balance_remaining_ugx') ?? 0;

        $totalDisbursed = DB::table('sacco_loans')
            ->whereIn('status', ['active', 'disbursed', 'completed'])
            ->sum('principal_amount_ugx') ?? 0;

        return response()->json([
            'data' => [
                'total_members' => $totalMembers,
                'total_loans' => $totalLoans,
                'active_loans' => $activeLoans,
                'pending_loans' => $pendingLoans,
                'total_savings' => $totalSavings,
                'total_loans_amount' => $totalLoansAmount,
                'total_disbursed' => $totalDisbursed,
            ],
        ]);
    }

    /**
     * Get members list.
     */
    public function members(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $status = $request->get('status');

        $query = DB::table('sacco_members')
            ->leftJoin('users', 'sacco_members.user_id', '=', 'users.id')
            ->select(
                'sacco_members.*',
                'users.username',
                'users.email',
                DB::raw('(SELECT SUM(balance_ugx) FROM sacco_savings_accounts WHERE member_id = sacco_members.id) as total_savings'),
                DB::raw('(SELECT COUNT(*) FROM sacco_loans WHERE member_id = sacco_members.id) as loans_count')
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.username', 'LIKE', "%{$search}%")
                  ->orWhere('users.email', 'LIKE', "%{$search}%")
                  ->orWhere('sacco_members.member_number', 'LIKE', "%{$search}%");
            });
        }

        if ($status && $status !== 'all') {
            $query->where('sacco_members.status', $status);
        }

        $members = $query->orderBy('sacco_members.joined_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $members->items(),
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
            ],
        ]);
    }

    /**
     * Get loans list.
     */
    public function loans(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $status = $request->get('status');

        $query = DB::table('sacco_loans')
            ->leftJoin('sacco_members', 'sacco_loans.member_id', '=', 'sacco_members.id')
            ->leftJoin('users', 'sacco_members.user_id', '=', 'users.id')
            ->select(
                'sacco_loans.*',
                'users.username as member_name',
                'users.email as member_email',
                'sacco_members.member_number'
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.username', 'LIKE', "%{$search}%")
                  ->orWhere('sacco_loans.loan_number', 'LIKE', "%{$search}%");
            });
        }

        if ($status && $status !== 'all') {
            $query->where('sacco_loans.status', $status);
        }

        $loans = $query->orderBy('sacco_loans.created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $loans->items(),
            'meta' => [
                'current_page' => $loans->currentPage(),
                'last_page' => $loans->lastPage(),
                'per_page' => $loans->perPage(),
                'total' => $loans->total(),
            ],
        ]);
    }

    /**
     * Get single loan.
     */
    public function showLoan($id)
    {
        $loan = DB::table('sacco_loans')
            ->leftJoin('sacco_members', 'sacco_loans.member_id', '=', 'sacco_members.id')
            ->leftJoin('users', 'sacco_members.user_id', '=', 'users.id')
            ->select(
                'sacco_loans.*',
                'users.username as member_name',
                'users.email as member_email',
                'sacco_members.member_number'
            )
            ->where('sacco_loans.id', $id)
            ->first();

        if (!$loan) {
            return response()->json([
                'message' => 'Loan not found.',
            ], 404);
        }

        return response()->json([
            'data' => $loan,
        ]);
    }

    /**
     * Approve loan.
     */
    public function approveLoan(Request $request, $id)
    {
        DB::table('sacco_loans')->where('id', $id)->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => auth()->id() ?? 1,
            'approval_notes' => $request->input('notes'),
        ]);

        return response()->json([
            'message' => 'Loan approved successfully.',
        ]);
    }

    /**
     * Reject loan.
     */
    public function rejectLoan(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        DB::table('sacco_loans')->where('id', $id)->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id() ?? 1,
        ]);

        return response()->json([
            'message' => 'Loan rejected.',
        ]);
    }

    /**
     * Disburse loan.
     */
    public function disburseLoan(Request $request, $id)
    {
        $validated = $request->validate([
            'disbursement_method' => 'required|string',
            'disbursement_reference' => 'nullable|string',
        ]);

        DB::table('sacco_loans')->where('id', $id)->update([
            'status' => 'disbursed',
            'disbursed_at' => now(),
            'disbursement_method' => $validated['disbursement_method'],
            'disbursement_reference' => $validated['disbursement_reference'] ?? null,
        ]);

        return response()->json([
            'message' => 'Loan disbursed successfully.',
        ]);
    }

    /**
     * Get loan repayments.
     */
    public function loanRepayments($id)
    {
        $repayments = DB::table('sacco_loan_repayments')
            ->where('loan_id', $id)
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json([
            'data' => $repayments,
        ]);
    }

    /**
     * Get savings transactions.
     */
    public function savingsTransactions(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $memberId = $request->get('member_id');

        $query = DB::table('sacco_savings_transactions')
            ->leftJoin('sacco_savings_accounts', 'sacco_savings_transactions.savings_account_id', '=', 'sacco_savings_accounts.id')
            ->leftJoin('sacco_members', 'sacco_savings_accounts.member_id', '=', 'sacco_members.id')
            ->leftJoin('users', 'sacco_members.user_id', '=', 'users.id')
            ->select(
                'sacco_savings_transactions.*',
                'users.username as member_name',
                'sacco_members.member_number'
            );

        if ($memberId) {
            $query->where('sacco_savings_accounts.member_id', $memberId);
        }

        $transactions = $query->orderBy('sacco_savings_transactions.transaction_date', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}
