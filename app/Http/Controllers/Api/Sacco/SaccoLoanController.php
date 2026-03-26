<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaccoLoanResource;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanProduct;
use App\Models\Sacco\SaccoLoanRepayment;
use App\Models\Sacco\SaccoMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoLoanController extends Controller
{
    /**
     * GET /api/sacco/loan-products -- list active loan products for the current member
     */
    public function products(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);

        $products = SaccoLoanProduct::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(function (SaccoLoanProduct $product) use ($member) {
                $maxEligibleAmount = min(
                    (float) $product->max_amount,
                    max((float) $member->calculateLoanEligibility(), (float) $product->min_amount)
                );

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'min_amount' => (float) $product->min_amount,
                    'max_amount' => (float) $product->max_amount,
                    'interest_rate' => (float) $product->interest_rate,
                    'min_duration_months' => $product->minDurationMonths,
                    'max_duration_months' => $product->maxDurationMonths,
                    'processing_fee_rate' => (float) $product->processingFeeRate,
                    'requires_guarantors' => $product->requiresGuarantors,
                    'min_guarantors' => $product->min_guarantors,
                    'is_eligible' => $member->isActive(),
                    'max_eligible_amount' => $maxEligibleAmount,
                    'eligibility_requirements' => [
                        'min_savings' => (float) $product->minSavingsBalance,
                        'min_shares' => $product->minShares,
                        'min_membership_months' => $product->minMembershipMonths,
                        'min_credit_score' => $product->minCreditScore,
                    ],
                ];
            });

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * GET /api/sacco/loans/eligibility -- member eligibility summary
     */
    public function eligibility(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);
        $product = $request->filled('product_id')
            ? SaccoLoanProduct::findOrFail((int) $request->input('product_id'))
            : null;

        $maxAmount = $product
            ? min((float) $product->max_amount, $member->calculateLoanEligibility())
            : $member->calculateLoanEligibility();

        $reasons = [];
        if (! $member->isActive()) {
            $reasons[] = 'Membership must be active.';
        }
        if ($product && $member->credit_score < $product->minCreditScore) {
            $reasons[] = "Minimum credit score of {$product->minCreditScore} required.";
        }
        if ($product && $member->total_savings < (float) $product->minSavingsBalance) {
            $reasons[] = 'Minimum savings requirement not met.';
        }
        if ($product && $member->total_shares < $product->minShares) {
            $reasons[] = 'Minimum shareholding requirement not met.';
        }

        $joinedAt = $member->joined_at ?? $member->created_at;
        $membershipMonths = $joinedAt ? $joinedAt->diffInMonths(now()) : 0;
        if ($product && $membershipMonths < $product->minMembershipMonths) {
            $reasons[] = "Minimum {$product->minMembershipMonths} months of membership required.";
        }

        return response()->json([
            'data' => [
                'is_eligible' => $member->canApplyForLoan() && count($reasons) === 0,
                'max_amount' => $maxAmount,
                'credit_score' => $member->credit_score,
                'savings_balance' => $member->total_savings,
                'shares_value' => $member->total_shares,
                'membership_months' => $membershipMonths,
                'reasons' => $reasons,
                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'requires_guarantors' => $product->requiresGuarantors,
                    'min_guarantors' => $product->min_guarantors,
                ] : null,
            ],
        ]);
    }

    /**
     * GET /api/sacco/loans/guarantors -- active members that can act as guarantors
     */
    public function guarantors(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);
        $search = trim((string) $request->input('search', ''));

        $guarantors = SaccoMember::query()
            ->where('status', 'active')
            ->where('id', '!=', $member->id)
            ->with('user:id,username,email,name')
            ->when($search !== '', function ($query) use ($search) {
                $escaped = escape_like($search);

                $query->whereHas('user', function ($userQuery) use ($escaped) {
                    $userQuery->where('username', 'like', "%{$escaped}%")
                        ->orWhere('name', 'like', "%{$escaped}%")
                        ->orWhere('email', 'like', "%{$escaped}%");
                });
            })
            ->limit(50)
            ->get()
            ->map(function (SaccoMember $guarantor) {
                return [
                    'id' => $guarantor->id,
                    'name' => $guarantor->user?->username ?? $guarantor->user?->name,
                    'member_number' => $guarantor->member_number,
                    'credit_score' => $guarantor->credit_score,
                    'total_savings' => (float) $guarantor->total_savings,
                    'shares_value' => (float) $guarantor->total_shares,
                    'active_loans' => $guarantor->loans()->whereIn('status', ['approved', 'disbursed', 'active'])->count(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $guarantors,
        ]);
    }

    /**
     * POST /api/sacco/loans/calculate-schedule -- preview a repayment schedule
     */
    public function calculateSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:sacco_loan_products,id',
            'amount' => 'required|numeric|min:10000',
            'term_months' => 'nullable|integer|min:1|max:60',
            'duration_months' => 'nullable|integer|min:1|max:60',
        ]);

        $product = SaccoLoanProduct::findOrFail((int) $validated['product_id']);
        $months = (int) ($validated['term_months'] ?? $validated['duration_months'] ?? 0);

        if ($months < $product->minDurationMonths || $months > $product->maxDurationMonths) {
            return response()->json([
                'message' => "Loan term must be between {$product->minDurationMonths} and {$product->maxDurationMonths} months.",
            ], 422);
        }

        $principal = (float) $validated['amount'];
        $costs = $product->calculateTotalLoanCost($principal, $months);
        $remaining = (float) $costs['total_amount'];
        $startDate = now()->addMonth()->startOfMonth();
        $schedule = [];

        for ($installment = 1; $installment <= $months; $installment++) {
            $payment = min((float) $costs['monthly_installment'], $remaining);
            $remaining -= $payment;

            $schedule[] = [
                'installment' => $installment,
                'due_date' => $startDate->copy()->addMonths($installment - 1)->toDateString(),
                'amount_ugx' => round($payment, 2),
                'balance_after_ugx' => round(max(0, $remaining), 2),
            ];
        }

        return response()->json([
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                ],
                'summary' => [
                    'principal_amount' => round($principal, 2),
                    'interest_amount' => round((float) $costs['interest_amount'], 2),
                    'processing_fee' => round((float) $costs['processing_fee'], 2),
                    'insurance_fee' => round((float) $costs['insurance_fee'], 2),
                    'total_amount' => round((float) $costs['total_amount'], 2),
                    'monthly_installment' => round((float) $costs['monthly_installment'], 2),
                    'term_months' => $months,
                ],
                'schedule' => $schedule,
            ],
        ]);
    }

    /**
     * GET /api/sacco/loans -- list authenticated user's own loans
     */
    public function myLoans(Request $request): JsonResponse
    {
        $user = $request->user();
        $member = SaccoMember::where('user_id', $user->id)->first();

        if (! $member) {
            return response()->json(['data' => []], 200);
        }

        $loans = SaccoLoan::where('member_id', $member->id)
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($this->getPerPage($request, 15));

        return response()->json(SaccoLoanResource::collection($loans)->response()->getData());
    }

    /**
     * POST /api/sacco/loans/apply — submit loan application
     */
    public function apply(Request $request): JsonResponse
    {
        $paymentMethod = $this->normalizePaymentMethod($request->input('payment_method'));
        if ($paymentMethod !== null) {
            $request->merge(['payment_method' => $paymentMethod]);
        }

        $validated = $request->validate([
            'member_id' => 'nullable|integer|exists:sacco_members,id',
            'product_id' => 'nullable|integer|exists:sacco_loan_products,id',
            'loan_type' => 'nullable|string|in:normal,emergency,development,school_fees',
            'principal_amount_ugx' => 'nullable|numeric|min:10000',
            'amount' => 'nullable|numeric|min:10000',
            'tenure_months' => 'nullable|integer|min:1|max:60',
            'term_months' => 'nullable|integer|min:1|max:60',
            'purpose' => 'nullable|string|max:1000',
            'phone_number' => 'nullable|string|max:20',
            'payment_method' => 'nullable|string|in:zengapay,mtn_momo,airtel_money,manual,bank',
        ]);

        $member = isset($validated['member_id'])
            ? SaccoMember::where('id', $validated['member_id'])
                ->where('status', 'active')
                ->firstOrFail()
            : $this->getAuthenticatedMember($request);

        $product = isset($validated['product_id'])
            ? SaccoLoanProduct::findOrFail($validated['product_id'])
            : null;

        $principalAmount = (float) ($validated['principal_amount_ugx'] ?? $validated['amount'] ?? 0);
        $tenureMonths = (int) ($validated['tenure_months'] ?? $validated['term_months'] ?? 0);

        if ($principalAmount < 10000) {
            return response()->json([
                'message' => 'The amount field must be at least 10000.',
            ], 422);
        }

        if ($tenureMonths < 1 || $tenureMonths > 60) {
            return response()->json([
                'message' => 'The term_months field must be between 1 and 60 months.',
            ], 422);
        }

        $member = SaccoMember::where('id', $member->id)
            ->where('status', 'active')
            ->firstOrFail();

        // Check for existing active loan
        $existingLoan = SaccoLoan::where('member_id', $member->id)
            ->whereIn('status', ['pending', 'approved', 'disbursed', 'active'])
            ->first();

        if ($existingLoan) {
            return response()->json([
                'message' => 'Member already has an active loan. Clear existing loan before applying.',
            ], 422);
        }

        $interestRate = (float) ($product?->interest_rate ?? config('sacco.loan_interest_rate', 12));
        $totalInterest = ($principalAmount * $interestRate * $tenureMonths) / (12 * 100);
        $totalPayable = $principalAmount + $totalInterest;
        $monthlyInstallment = ceil($totalPayable / $tenureMonths);

        $loan = SaccoLoan::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'loan_product_id' => $product?->id,
            'loan_type' => $validated['loan_type'] ?? $product?->loan_type ?? 'normal',
            'principal_amount_ugx' => $principalAmount,
            'interest_rate' => $interestRate,
            'total_interest_ugx' => $totalInterest,
            'total_payable_ugx' => $totalPayable,
            'amount_paid_ugx' => 0,
            'balance_remaining_ugx' => $totalPayable,
            'tenure_months' => $tenureMonths,
            'monthly_installment_ugx' => $monthlyInstallment,
            'purpose' => $validated['purpose'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => new SaccoLoanResource($loan),
            'message' => 'Loan application submitted successfully.',
        ], 201);
    }

    /**
     * POST /api/sacco/loans/{loan}/approve — approve loan
     */
    public function approve(Request $request, $loan): JsonResponse
    {
        $loan = SaccoLoan::where('status', 'pending')->findOrFail($loan);

        $loan->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id ?? null,
        ]);

        return response()->json([
            'data' => new SaccoLoanResource($loan->fresh()),
            'message' => 'Loan approved successfully.',
        ]);
    }

    /**
     * POST /api/sacco/loans/{loan}/disburse — disburse approved loan
     */
    public function disburse(Request $request, $loan): JsonResponse
    {
        $loan = SaccoLoan::where('status', 'approved')->findOrFail($loan);

        $now = now();
        $firstPaymentDate = $now->copy()->addMonth()->startOfMonth();

        $loan->update([
            'status' => 'disbursed',
            'disbursement_date' => $now,
            'disbursed_at' => $now,
            'first_payment_date' => $firstPaymentDate,
            'maturity_date' => $firstPaymentDate->copy()->addMonths($loan->tenure_months - 1),
        ]);

        return response()->json([
            'data' => new SaccoLoanResource($loan->fresh()),
            'message' => 'Loan disbursed successfully.',
        ]);
    }

    /**
     * POST /api/sacco/loans/{loan}/repay — make a repayment
     */
    public function repay(Request $request, $loan): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'nullable|string|max:50',
            'reference_number' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:20',
        ]);

        $loan = SaccoLoan::whereIn('status', ['disbursed', 'active'])
            ->findOrFail($loan);

        if ($loan->balance_remaining_ugx <= 0) {
            return response()->json(['message' => 'Loan is already fully paid.'], 422);
        }

        $amount = min($validated['amount'], $loan->balance_remaining_ugx);

        DB::transaction(function () use ($loan, $amount, $validated) {
            // Simplified allocation: interest first, remainder to principal
            $interestOutstanding = max(0, $loan->total_interest_ugx - ($loan->amount_paid_ugx > $loan->principal_amount_ugx ? $loan->amount_paid_ugx - $loan->principal_amount_ugx : 0));
            $interestPaid = min($amount, $interestOutstanding);
            $principalPaid = $amount - $interestPaid;

            SaccoLoanRepayment::create([
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'amount_ugx' => $amount,
                'principal_paid_ugx' => $principalPaid,
                'interest_paid_ugx' => $interestPaid,
                'penalty_paid_ugx' => 0,
                'payment_date' => now(),
                'payment_method' => $validated['payment_method'] ?? 'manual',
                'reference_number' => $validated['reference_number'] ?? null,
            ]);

            $loan->increment('amount_paid_ugx', $amount);
            $loan->decrement('balance_remaining_ugx', $amount);

            if ($loan->fresh()->balance_remaining_ugx <= 0) {
                $loan->update(['status' => 'paid']);
            }
        });

        return response()->json([
            'data' => new SaccoLoanResource($loan->fresh()),
            'message' => 'Repayment recorded successfully.',
        ]);
    }

    /**
     * GET /api/sacco/loans/{loan} — loan detail
     */
    public function show($loan)
    {
        $loan = SaccoLoan::with(['member.user:id,username,email', 'repayments', 'loanProduct'])
            ->findOrFail($loan);

        return new SaccoLoanResource($loan);
    }

    /**
     * GET /api/sacco/loans/member/{member} — member's loans
     */
    public function memberLoans(Request $request, $member)
    {
        $loans = SaccoLoan::where('member_id', $member)
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($this->getPerPage($request, 15));

        return SaccoLoanResource::collection($loans);
    }

    /**
     * GET /api/sacco/loans/{loan}/schedule — repayment schedule
     */
    public function schedule($loan): JsonResponse
    {
        $loan = SaccoLoan::findOrFail($loan);

        $schedule = [];
        $remaining = $loan->total_payable_ugx;
        $monthlyInstallment = $loan->monthly_installment_ugx;
        $startDate = $loan->first_payment_date ?? now()->addMonth()->startOfMonth();

        for ($i = 1; $i <= $loan->tenure_months; $i++) {
            $payment = min($monthlyInstallment, $remaining);
            $remaining -= $payment;
            $schedule[] = [
                'installment' => $i,
                'due_date' => $startDate->copy()->addMonths($i - 1)->toDateString(),
                'amount_ugx' => $payment,
                'balance_after_ugx' => max(0, $remaining),
            ];
        }

        return response()->json([
            'data' => [
                'loan_id' => $loan->id,
                'loan_number' => $loan->loan_number,
                'schedule' => $schedule,
            ],
        ]);
    }

    /**
     * GET /api/sacco/loans/{loan}/balance — loan balance
     */
    public function balance($loan): JsonResponse
    {
        $loan = SaccoLoan::findOrFail($loan);

        return response()->json([
            'data' => [
                'loan_number' => $loan->loan_number,
                'principal_amount_ugx' => $loan->principal_amount_ugx,
                'total_payable_ugx' => $loan->total_payable_ugx,
                'amount_paid_ugx' => $loan->amount_paid_ugx,
                'balance_remaining_ugx' => $loan->balance_remaining_ugx,
                'status' => $loan->status,
                'is_fully_paid' => $loan->balance_remaining_ugx <= 0,
            ],
        ]);
    }

    private function getAuthenticatedMember(Request $request): SaccoMember
    {
        return SaccoMember::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function normalizePaymentMethod(?string $paymentMethod): ?string
    {
        return match ($paymentMethod) {
            'mtn_momo', 'airtel_money' => 'zengapay',
            default => $paymentMethod,
        };
    }
}
