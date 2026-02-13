<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Sacco\Models\SaccoMember;
use App\Modules\Sacco\Models\SaccoAccount;
use App\Modules\Sacco\Models\SaccoLoan;
use App\Modules\Sacco\Models\SaccoLoanProduct;
use App\Modules\Sacco\Models\SaccoTransaction;
use App\Modules\Sacco\Services\SaccoLoanService;
use App\Modules\Sacco\Services\SaccoAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * SACCO API Controller for AJAX/Mobile App requests
 */
class SaccoApiController extends Controller
{
    protected $loanService;
    protected $accountService;

    public function __construct(
        SaccoLoanService $loanService,
        SaccoAccountService $accountService
    ) {
        $this->loanService = $loanService;
        $this->accountService = $accountService;
    }

    /**
     * Get member dashboard stats
     */
    public function dashboard(): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            
            $stats = [
                'member' => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'status' => $member->status,
                    'credit_score' => $member->credit_score,
                    'joined_at' => $member->created_at->format('Y-m-d'),
                ],
                'accounts' => [
                    'savings' => $member->accounts()
                        ->where('account_type', 'savings')
                        ->sum('balance'),
                    'shares' => $member->accounts()
                        ->where('account_type', 'shares')
                        ->sum('balance'),
                    'fixed_deposits' => $member->accounts()
                        ->where('account_type', 'fixed_deposit')
                        ->sum('balance'),
                ],
                'loans' => [
                    'active_count' => $member->loans()->where('status', 'active')->count(),
                    'total_borrowed' => $member->loans()->sum('principal_amount'),
                    'total_outstanding' => $member->loans()->where('status', 'active')->sum('outstanding_balance'),
                    'total_paid' => $member->loans()->sum('amount_paid'),
                ],
                'transactions' => [
                    'today' => $member->transactions()->whereDate('created_at', today())->count(),
                    'this_month' => $member->transactions()->whereMonth('created_at', now()->month)->count(),
                    'total_volume' => $member->transactions()->sum('amount'),
                ],
                'dividends' => [
                    'total_earned' => $member->dividends()->where('status', 'distributed')->sum('amount'),
                    'pending' => $member->dividends()->where('status', 'pending')->sum('amount'),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get member accounts
     */
    public function accounts(): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            $accounts = $member->accounts()->get()->map(function ($account) {
                return [
                    'id' => $account->id,
                    'account_number' => $account->account_number,
                    'account_type' => $account->account_type,
                    'balance' => $account->balance,
                    'interest_rate' => $account->interest_rate,
                    'status' => $account->status,
                    'created_at' => $account->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $accounts,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load accounts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get account transactions
     */
    public function accountTransactions(Request $request, SaccoAccount $account): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            
            if ($account->member_id !== $member->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access',
                ], 403);
            }

            $perPage = $request->input('per_page', 20);
            $transactions = $account->transactions()
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get member loans
     */
    public function loans(): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            $loans = $member->loans()->with('loanProduct')->get()->map(function ($loan) {
                return [
                    'id' => $loan->id,
                    'application_number' => $loan->application_number,
                    'product' => $loan->loanProduct->name,
                    'principal_amount' => $loan->principal_amount,
                    'interest_rate' => $loan->interest_rate,
                    'total_amount' => $loan->total_amount,
                    'amount_paid' => $loan->amount_paid,
                    'outstanding_balance' => $loan->outstanding_balance,
                    'status' => $loan->status,
                    'disbursement_date' => $loan->disbursement_date?->format('Y-m-d'),
                    'due_date' => $loan->due_date?->format('Y-m-d'),
                    'next_payment_date' => $loan->next_payment_date?->format('Y-m-d'),
                    'next_payment_amount' => $loan->next_payment_amount,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $loans,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load loans',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get loan products
     */
    public function loanProducts(): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            
            $products = SaccoLoanProduct::where('is_active', true)
                ->get()
                ->map(function ($product) use ($member) {
                    $isEligible = $this->loanService->checkLoanEligibility($member, $product);
                    $maxAmount = $isEligible ? $this->loanService->calculateMaxLoanAmount($member, $product) : 0;

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'min_amount' => $product->min_amount,
                        'max_amount' => $product->max_amount,
                        'interest_rate' => $product->interest_rate,
                        'min_duration_months' => $product->min_duration_months,
                        'max_duration_months' => $product->max_duration_months,
                        'processing_fee_rate' => $product->processing_fee_rate,
                        'requires_guarantors' => $product->requires_guarantors,
                        'min_guarantors' => $product->min_guarantors,
                        'is_eligible' => $isEligible,
                        'max_eligible_amount' => $maxAmount,
                        'eligibility_requirements' => [
                            'min_savings' => $product->min_savings_balance,
                            'min_shares' => $product->min_shares,
                            'min_membership_months' => $product->min_membership_months,
                            'min_credit_score' => $product->min_credit_score,
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $products,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load loan products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate loan repayment schedule
     */
    public function calculateLoanSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:sacco_loan_products,id',
            'amount' => 'required|numeric|min:1',
            'duration_months' => 'required|integer|min:1',
        ]);

        try {
            $product = SaccoLoanProduct::findOrFail($validated['product_id']);
            
            $schedule = $this->loanService->calculateRepaymentSchedule(
                $validated['amount'],
                $product->interest_rate,
                $validated['duration_months'],
                $product->processing_fee_rate
            );

            return response()->json([
                'success' => true,
                'data' => $schedule,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate loan schedule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get loan details
     */
    public function loanDetails(SaccoLoan $loan): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            
            if ($loan->member_id !== $member->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access',
                ], 403);
            }

            $loan->load(['loanProduct', 'guarantors.guarantorMember.user', 'repayments']);

            return response()->json([
                'success' => true,
                'data' => [
                    'loan' => [
                        'id' => $loan->id,
                        'application_number' => $loan->application_number,
                        'product' => $loan->loanProduct->name,
                        'principal_amount' => $loan->principal_amount,
                        'interest_rate' => $loan->interest_rate,
                        'processing_fee' => $loan->processing_fee,
                        'total_amount' => $loan->total_amount,
                        'amount_paid' => $loan->amount_paid,
                        'outstanding_balance' => $loan->outstanding_balance,
                        'status' => $loan->status,
                        'duration_months' => $loan->duration_months,
                        'applied_at' => $loan->created_at->format('Y-m-d'),
                        'approved_at' => $loan->approved_at?->format('Y-m-d'),
                        'disbursement_date' => $loan->disbursement_date?->format('Y-m-d'),
                        'due_date' => $loan->due_date?->format('Y-m-d'),
                        'next_payment_date' => $loan->next_payment_date?->format('Y-m-d'),
                        'next_payment_amount' => $loan->next_payment_amount,
                    ],
                    'guarantors' => $loan->guarantors->map(function ($guarantor) {
                        return [
                            'name' => $guarantor->guarantorMember->user->name,
                            'member_number' => $guarantor->guarantorMember->member_number,
                            'status' => $guarantor->status,
                            'amount_guaranteed' => $guarantor->amount_guaranteed,
                        ];
                    }),
                    'repayments' => $loan->repayments->map(function ($repayment) {
                        return [
                            'amount' => $repayment->amount,
                            'principal_amount' => $repayment->principal_amount,
                            'interest_amount' => $repayment->interest_amount,
                            'payment_method' => $repayment->payment_method,
                            'payment_reference' => $repayment->payment_reference,
                            'status' => $repayment->status,
                            'paid_at' => $repayment->created_at->format('Y-m-d H:i:s'),
                        ];
                    }),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load loan details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            
            $perPage = $request->input('per_page', 20);
            $type = $request->input('type'); // deposit, withdrawal, loan_repayment, etc.
            
            $query = $member->transactions()->with('account')->latest();
            
            if ($type) {
                $query->where('transaction_type', $type);
            }
            
            $transactions = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get potential guarantors
     */
    public function potentialGuarantors(Request $request): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            
            $search = $request->input('search');
            
            $query = SaccoMember::where('status', 'active')
                ->where('id', '!=', $member->id)
                ->with('user');
            
            if ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            
            $guarantors = $query->limit(50)->get()->map(function ($guarantor) {
                return [
                    'id' => $guarantor->id,
                    'name' => $guarantor->user->name,
                    'member_number' => $guarantor->member_number,
                    'credit_score' => $guarantor->credit_score,
                    'total_savings' => $guarantor->accounts()->where('account_type', 'savings')->sum('balance'),
                    'active_loans' => $guarantor->loans()->where('status', 'active')->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $guarantors,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load guarantors',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check loan eligibility
     */
    public function checkEligibility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:sacco_loan_products,id',
        ]);

        try {
            $member = SaccoMember::where('user_id', auth()->id())->firstOrFail();
            $product = SaccoLoanProduct::findOrFail($validated['product_id']);

            $isEligible = $this->loanService->checkLoanEligibility($member, $product);
            $maxAmount = $isEligible ? $this->loanService->calculateMaxLoanAmount($member, $product) : 0;
            $reasons = $this->loanService->getIneligibilityReasons($member, $product);

            return response()->json([
                'success' => true,
                'data' => [
                    'is_eligible' => $isEligible,
                    'max_amount' => $maxAmount,
                    'reasons' => $reasons,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check eligibility',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get member profile
     */
    public function profile(): JsonResponse
    {
        try {
            $member = SaccoMember::where('user_id', auth()->id())
                ->with('user')
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $member->id,
                    'user' => [
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                    ],
                    'member_number' => $member->member_number,
                    'national_id' => $member->national_id,
                    'phone_number' => $member->user->phone,
                    'date_of_birth' => $member->date_of_birth,
                    'address' => $member->address,
                    'occupation' => $member->occupation,
                    'employer' => $member->employer,
                    'monthly_income' => $member->monthly_income,
                    'credit_score' => $member->credit_score,
                    'status' => $member->status,
                    'joined_at' => $member->created_at->format('Y-m-d'),
                    'next_of_kin' => [
                        'name' => $member->next_of_kin_name,
                        'phone' => $member->next_of_kin_phone,
                        'relationship' => $member->next_of_kin_relationship,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
