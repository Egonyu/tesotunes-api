<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanRepayment;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SaccoApiController extends Controller
{
    use HandlesApiErrors;

    /**
     * Get SACCO statistics.
     */
    public function stats(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:sacco:stats', now()->addMinutes(5), function () {
                $totalMembers = SaccoMember::where('status', 'active')->count();
                $totalLoans = SaccoLoan::count();
                $activeLoans = SaccoLoan::whereIn('status', ['active', 'disbursed'])->count();
                $pendingLoans = SaccoLoan::where('status', 'pending')->count();

                $totalSavings = SaccoSavingsAccount::sum('balance_ugx') ?? 0;
                $totalLoansAmount = SaccoLoan::where('status', 'active')->sum('balance_remaining_ugx') ?? 0;
                $totalDisbursed = SaccoLoan::whereIn('status', ['active', 'disbursed', 'completed'])
                    ->sum('principal_amount_ugx') ?? 0;

                return [
                    'total_members' => $totalMembers,
                    'total_loans' => $totalLoans,
                    'active_loans' => $activeLoans,
                    'pending_loans' => $pendingLoans,
                    'total_savings' => $totalSavings,
                    'total_loans_amount' => $totalLoansAmount,
                    'total_disbursed' => $totalDisbursed,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to fetch SACCO statistics.');
    }

    /**
     * Get members list.
     */
    public function members(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $members = SaccoMember::with('user:id,username,email')
                ->withCount('loans')
                ->when($request->get('search'), function ($q) use ($request) {
                    $search = addcslashes($request->get('search'), '%_');
                    $q->where(function ($query) use ($search) {
                        $query->where('member_number', 'LIKE', "%{$search}%")
                            ->orWhereHas('user', function ($uq) use ($search) {
                                $uq->where('username', 'LIKE', "%{$search}%")
                                    ->orWhere('email', 'LIKE', "%{$search}%");
                            });
                    });
                })
                ->when($request->get('status') && $request->get('status') !== 'all', function ($q) use ($request) {
                    $q->where('status', $request->get('status'));
                })
                ->latest('joined_at')
                ->paginate($perPage);

            $data = $members->through(function (SaccoMember $member) {
                // Get total savings via subquery (savings accounts belong to member)
                $totalSavings = SaccoSavingsAccount::where('member_id', $member->id)
                    ->sum('balance_ugx');

                return [
                    ...$member->toArray(),
                    'username' => $member->user?->username,
                    'email' => $member->user?->email,
                    'total_savings' => $totalSavings ?? 0,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ]);
        }, 'Failed to fetch SACCO members.');
    }

    /**
     * Get loans list.
     */
    public function loans(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $loans = SaccoLoan::with(['member.user:id,username,email', 'member:id,member_number,user_id'])
                ->when($request->get('search'), function ($q) use ($request) {
                    $search = addcslashes($request->get('search'), '%_');
                    $q->where(function ($query) use ($search) {
                        $query->where('loan_number', 'LIKE', "%{$search}%")
                            ->orWhereHas('member.user', function ($uq) use ($search) {
                                $uq->where('username', 'LIKE', "%{$search}%");
                            });
                    });
                })
                ->when($request->get('status') && $request->get('status') !== 'all', function ($q) use ($request) {
                    $q->where('status', $request->get('status'));
                })
                ->latest()
                ->paginate($perPage);

            $data = $loans->through(function (SaccoLoan $loan) {
                return [
                    ...$loan->toArray(),
                    'member_name' => $loan->member?->user?->username,
                    'member_email' => $loan->member?->user?->email,
                    'member_number' => $loan->member?->member_number,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ]);
        }, 'Failed to fetch SACCO loans.');
    }

    /**
     * Get single loan.
     */
    public function showLoan($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $loan = SaccoLoan::with(['member.user:id,username,email', 'member:id,member_number,user_id'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    ...$loan->toArray(),
                    'member_name' => $loan->member?->user?->username,
                    'member_email' => $loan->member?->user?->email,
                    'member_number' => $loan->member?->member_number,
                ],
            ]);
        }, 'Failed to fetch loan details.');
    }

    /**
     * Approve loan.
     */
    public function approveLoan(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $loan = SaccoLoan::findOrFail($id);

            // Use forceFill since status/approved_by are guarded (financial protection)
            $loan->forceFill([
                'status' => 'approved',
                'approved_date' => now(),
                'approved_by' => auth()->id(),
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Loan approved successfully.',
            ]);
        }, 'Failed to approve loan.');
    }

    /**
     * Reject loan.
     */
    public function rejectLoan(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $validated = $request->validate([
                'reason' => 'required|string',
            ]);

            $loan = SaccoLoan::findOrFail($id);

            $loan->forceFill([
                'status' => 'rejected',
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Loan rejected.',
            ]);
        }, 'Failed to reject loan.');
    }

    /**
     * Disburse loan.
     */
    public function disburseLoan(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $validated = $request->validate([
                'disbursement_method' => 'required|string',
                'disbursement_reference' => 'nullable|string',
            ]);

            $loan = SaccoLoan::findOrFail($id);

            $loan->forceFill([
                'status' => 'disbursed',
                'disbursed_date' => now(),
                'disbursed_by' => auth()->id(),
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Loan disbursed successfully.',
            ]);
        }, 'Failed to disburse loan.');
    }

    /**
     * Get loan repayments.
     */
    public function loanRepayments($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            SaccoLoan::findOrFail($id); // Ensure loan exists

            $repayments = SaccoLoanRepayment::where('loan_id', $id)
                ->orderByDesc('payment_date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $repayments,
            ]);
        }, 'Failed to fetch loan repayments.');
    }

    /**
     * Get savings transactions.
     */
    public function savingsTransactions(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 20), 100);
            $memberId = $request->get('member_id');

            $query = SaccoSavingsTransaction::with(['account.member.user:id,username'])
                ->select('sacco_savings_transactions.*');

            if ($memberId) {
                $query->whereHas('account', function ($q) use ($memberId) {
                    $q->where('member_id', $memberId);
                });
            }

            $transactions = $query->orderByDesc('transaction_date')
                ->paginate($perPage);

            $data = $transactions->through(function ($txn) {
                return [
                    ...$txn->toArray(),
                    'member_name' => $txn->account?->member?->user?->username,
                    'member_number' => $txn->account?->member?->member_number,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ]);
        }, 'Failed to fetch savings transactions.');
    }
}
