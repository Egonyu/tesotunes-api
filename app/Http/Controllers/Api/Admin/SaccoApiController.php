<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanGuarantor;
use App\Models\Sacco\SaccoLoanRepayment;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoShare;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SaccoApiController extends Controller
{
    use HandlesApiErrors;

    protected function ensureAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access is required for this action.',
            ], 403);
        }

        return null;
    }

    /**
     * Get SACCO statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
                ->when($request->get('user_id'), function ($q) use ($request) {
                    $q->where('user_id', (int) $request->get('user_id'));
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
     * Get a single member with admin-facing summary fields.
     */
    public function showMember(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $member = SaccoMember::with('user:id,username,email')
                ->withCount([
                    'loans',
                    'loans as active_loans' => fn ($query) => $query->whereIn('status', ['approved', 'disbursed', 'active']),
                ])
                ->findOrFail($id);

            $savingsBalance = (float) SaccoSavingsAccount::where('member_id', $member->id)->sum('balance_ugx');
            $monthlyDeposits = (float) SaccoSavingsTransaction::where('member_id', $member->id)
                ->where('type', 'deposit')
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount_ugx');
            $shareRecord = SaccoShare::where('member_id', $member->id)->first();
            $totalBorrowed = (float) SaccoLoan::where('member_id', $member->id)->sum('principal_amount_ugx');
            $totalRepaid = (float) SaccoLoanRepayment::where('member_id', $member->id)->sum('amount_ugx');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'status' => $member->status,
                    'name' => $member->user?->username ?: 'Unknown Member',
                    'email' => $member->user?->email,
                    'phone' => $member->phone_number,
                    'member_since' => optional($member->joined_at)->toISOString(),
                    'created_at' => optional($member->created_at)->toISOString(),
                    'updated_at' => optional($member->updated_at)->toISOString(),
                    'last_activity' => optional($member->updated_at)->toISOString(),
                    'risk_profile' => $member->credit_score >= 650 ? 'Low' : ($member->credit_score >= 500 ? 'Medium' : 'High'),
                    'savings' => [
                        'balance' => $savingsBalance,
                        'this_month' => $monthlyDeposits,
                        'interest_earned' => 0,
                    ],
                    'shares' => [
                        'count' => (int) ($shareRecord?->total_shares ?? 0),
                        'value' => (float) ($shareRecord?->total_value_ugx ?? 0),
                        'dividends_earned' => 0,
                    ],
                    'loans' => [
                        'active' => (int) $member->active_loans,
                        'total' => (int) $member->loans_count,
                        'total_borrowed' => $totalBorrowed,
                        'total_repaid' => $totalRepaid,
                    ],
                ],
            ]);
        }, 'Failed to fetch SACCO member details.');
    }

    /**
     * Get savings transactions for a specific member.
     */
    public function memberTransactions(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            SaccoMember::findOrFail($id);

            $transactions = SaccoSavingsTransaction::where('member_id', $id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function (SaccoSavingsTransaction $transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'amount' => (float) ($transaction->amount_ugx ?? 0),
                        'description' => $transaction->description,
                        'date' => optional($transaction->created_at)->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $transactions,
            ]);
        }, 'Failed to fetch SACCO member transactions.');
    }

    /**
     * Get loans for a specific member.
     */
    public function memberLoans(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            SaccoMember::findOrFail($id);

            $loans = SaccoLoan::where('member_id', $id)
                ->latest()
                ->get()
                ->map(function (SaccoLoan $loan) {
                    $principal = (float) ($loan->principal_amount_ugx ?: $loan->principal_amount ?: 0);

                    return [
                        'id' => $loan->id,
                        'loan_number' => $loan->loan_number,
                        'type' => $loan->loan_type ?: 'standard',
                        'amount' => $principal,
                        'principal_amount' => $principal,
                        'status' => $loan->status,
                        'start_date' => optional($loan->disbursement_date ?? $loan->applied_at ?? $loan->created_at)->toISOString(),
                        'end_date' => optional($loan->due_date ?? $loan->maturity_date)->toISOString(),
                        'created_at' => optional($loan->created_at)->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $loans,
            ]);
        }, 'Failed to fetch SACCO member loans.');
    }

    /**
     * Get loans list.
     */
    public function loans(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
    public function showLoan(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $loan = SaccoLoan::with([
                'member.user:id,username,email',
                'member:id,member_number,user_id,phone_number',
            ])
                ->findOrFail($id);

            $memberSavings = (float) SaccoSavingsAccount::where('member_id', $loan->member_id)->sum('balance_ugx');
            $memberShares = (int) (SaccoShare::where('member_id', $loan->member_id)->value('total_shares') ?? 0);
            $guarantors = SaccoLoanGuarantor::with('guarantorMember.user:id,username,email')
                ->where('loan_id', $loan->id)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    ...$loan->toArray(),
                    'member_name' => $loan->member?->user?->username,
                    'member_email' => $loan->member?->user?->email,
                    'member_number' => $loan->member?->member_number,
                    'term_months' => $loan->term_months ?: $loan->tenure_months ?: $loan->duration_months,
                    'monthly_payment' => (float) ($loan->monthly_repayment ?: $loan->monthly_installment_ugx ?: 0),
                    'total_repayment' => (float) ($loan->total_amount ?: $loan->total_payable_ugx ?: 0),
                    'member' => [
                        'id' => $loan->member?->id,
                        'member_number' => $loan->member?->member_number,
                        'savings_balance' => $memberSavings,
                        'shares_count' => $memberShares,
                        'user' => [
                            'id' => $loan->member?->user?->id,
                            'name' => $loan->member?->user?->username,
                            'email' => $loan->member?->user?->email,
                            'phone' => $loan->member?->phone_number,
                        ],
                    ],
                    'guarantors' => $guarantors->map(fn ($guarantor) => [
                        'id' => $guarantor->id,
                        'name' => $guarantor->guarantorMember?->user?->username ?? ('Guarantor #'.$guarantor->id),
                        'relationship' => 'Member guarantor',
                        'shares_count' => (int) ($guarantor->guarantorMember?->shares?->total_shares ?? 0),
                        'status' => $guarantor->status ?? 'pending',
                    ])->values(),
                    'documents' => [],
                ],
            ]);
        }, 'Failed to fetch loan details.');
    }

    /**
     * Approve loan.
     */
    public function approveLoan(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
    public function loanRepayments(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

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

            $transactions = $query->orderByDesc('created_at')
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
