<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaccoSavingsAccountResource;
use App\Http\Resources\SaccoTransactionResource;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoSavingsController extends Controller
{
    /**
     * POST /api/sacco/savings/accounts — open account
     */
    public function openAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => 'required|integer|exists:sacco_members,id',
            'account_type' => 'nullable|string|in:regular,fixed_deposit,target,retirement',
            'account_name' => 'nullable|string|max:255',
            'interest_rate' => 'nullable|numeric|min:0',
            'minimum_balance_ugx' => 'nullable|numeric|min:0',
        ]);

        $member = SaccoMember::findOrFail($validated['member_id']);

        if ($member->status !== 'active') {
            return response()->json(['message' => 'Only active members can open accounts.'], 422);
        }

        $account = SaccoSavingsAccount::create([
            'member_id' => $member->id,
            'account_type' => $validated['account_type'] ?? 'regular',
            'account_name' => $validated['account_name'] ?? $validated['account_type'] ?? 'Savings Account',
            'interest_rate' => $validated['interest_rate'] ?? 0,
            'minimum_balance_ugx' => $validated['minimum_balance_ugx'] ?? 0,
        ]);

        return response()->json([
            'data' => new SaccoSavingsAccountResource($account),
            'message' => 'Savings account opened successfully.',
        ], 201);
    }

    /**
     * POST /api/sacco/savings/deposit — deposit
     */
    public function deposit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:sacco_savings_accounts,id',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:500',
            'reference_number' => 'nullable|string|max:100',
        ]);

        $account = SaccoSavingsAccount::findOrFail($validated['account_id']);

        if ($account->status !== 'active') {
            return response()->json(['message' => 'Account is not active.'], 422);
        }

        $balanceBefore = $account->balance_ugx;

        DB::transaction(function () use ($account, $validated, $balanceBefore) {
            $account->increment('balance_ugx', $validated['amount']);

            SaccoSavingsTransaction::create([
                'account_id' => $account->id,
                'member_id' => $account->member_id,
                'type' => 'deposit',
                'amount_ugx' => $validated['amount'],
                'balance_before_ugx' => $balanceBefore,
                'balance_after_ugx' => $balanceBefore + $validated['amount'],
                'description' => $validated['description'] ?? 'Savings deposit',
                'reference_number' => $validated['reference_number'] ?? null,
                'status' => 'completed',
            ]);
        });

        $account->refresh();

        return response()->json([
            'data' => new SaccoSavingsAccountResource($account),
            'message' => 'Deposit successful.',
        ]);
    }

    /**
     * POST /api/sacco/savings/withdraw — withdraw
     */
    public function withdraw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:sacco_savings_accounts,id',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:500',
            'reference_number' => 'nullable|string|max:100',
        ]);

        $account = SaccoSavingsAccount::findOrFail($validated['account_id']);

        if (! $account->canWithdraw($validated['amount'])) {
            return response()->json([
                'message' => 'Insufficient balance or account not active.',
                'data' => ['available_balance' => $account->balance_ugx - ($account->minimum_balance_ugx ?? 0)],
            ], 422);
        }

        $balanceBefore = $account->balance_ugx;

        DB::transaction(function () use ($account, $validated, $balanceBefore) {
            $account->decrement('balance_ugx', $validated['amount']);

            SaccoSavingsTransaction::create([
                'account_id' => $account->id,
                'member_id' => $account->member_id,
                'type' => 'withdrawal',
                'amount_ugx' => $validated['amount'],
                'balance_before_ugx' => $balanceBefore,
                'balance_after_ugx' => $balanceBefore - $validated['amount'],
                'description' => $validated['description'] ?? 'Savings withdrawal',
                'reference_number' => $validated['reference_number'] ?? null,
                'status' => 'completed',
            ]);
        });

        $account->refresh();

        return response()->json([
            'data' => new SaccoSavingsAccountResource($account),
            'message' => 'Withdrawal successful.',
        ]);
    }

    /**
     * GET /api/sacco/savings/accounts/{account} — account detail
     */
    public function show($account)
    {
        $account = SaccoSavingsAccount::with('member.user:id,username,email')
            ->findOrFail($account);

        return new SaccoSavingsAccountResource($account);
    }

    /**
     * GET /api/sacco/savings/transactions/{account} — transactions
     */
    public function transactions(Request $request, $account)
    {
        $account = SaccoSavingsAccount::findOrFail($account);

        $query = SaccoSavingsTransaction::where('account_id', $account->id);

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $transactions = $query->latest()
            ->paginate($this->getPerPage($request));

        return SaccoTransactionResource::collection($transactions);
    }

    /**
     * GET /api/sacco/savings/balance/{account} — balance
     */
    public function balance($account): JsonResponse
    {
        $account = SaccoSavingsAccount::findOrFail($account);

        return response()->json([
            'data' => [
                'account_number' => $account->account_number,
                'balance_ugx' => $account->balance_ugx,
                'accrued_interest_ugx' => $account->accrued_interest_ugx,
                'minimum_balance_ugx' => $account->minimum_balance_ugx,
                'available_for_withdrawal' => max(0, $account->balance_ugx - ($account->minimum_balance_ugx ?? 0)),
            ],
        ]);
    }
}
