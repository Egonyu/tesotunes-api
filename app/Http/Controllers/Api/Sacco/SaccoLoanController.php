<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaccoLoanResource;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoLoanRepayment;
use App\Models\Sacco\SaccoMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoLoanController extends Controller
{
    /**
     * GET /api/sacco/loans -- list authenticated user's own loans
     */
    public function myLoans(Request $request): JsonResponse
    {
        $user = $request->user();
        $member = SaccoMember::where('user_id', $user->id)->first();

        if (!$member) {
            return response()->json(['data' => []], 200);
        }

        $loans = SaccoLoan::where('member_id', $member->id)
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json(SaccoLoanResource::collection($loans)->response()->getData());
    }

    /**
     * POST /api/sacco/loans/apply — submit loan application
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => 'required|integer|exists:sacco_members,id',
            'loan_type' => 'nullable|string|in:normal,emergency,development,school_fees',
            'principal_amount_ugx' => 'required|numeric|min:10000',
            'tenure_months' => 'required|integer|min:1|max:60',
            'purpose' => 'nullable|string|max:1000',
        ]);

        $member = SaccoMember::where('id', $validated['member_id'])
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

        $interestRate = config('sacco.loan_interest_rate', 12);
        $totalInterest = ($validated['principal_amount_ugx'] * $interestRate * $validated['tenure_months']) / (12 * 100);
        $totalPayable = $validated['principal_amount_ugx'] + $totalInterest;
        $monthlyInstallment = ceil($totalPayable / $validated['tenure_months']);

        $loan = SaccoLoan::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'loan_type' => $validated['loan_type'] ?? 'normal',
            'principal_amount_ugx' => $validated['principal_amount_ugx'],
            'interest_rate' => $interestRate,
            'total_interest_ugx' => $totalInterest,
            'total_payable_ugx' => $totalPayable,
            'amount_paid_ugx' => 0,
            'balance_remaining_ugx' => $totalPayable,
            'tenure_months' => $validated['tenure_months'],
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
        $loan = SaccoLoan::with(['member.user:id,username,email', 'repayments'])
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
            ->paginate($request->get('per_page', 15));

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
}
