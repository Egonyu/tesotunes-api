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
use Illuminate\Validation\ValidationException;

class SaccoSavingsController extends Controller
{
    /**
     * GET /api/sacco/savings — member savings summary
     */
    public function summary(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);

        $accounts = SaccoSavingsAccount::query()
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->get();

        $goals = \App\Models\Sacco\SaccoGoal::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->orderBy('deadline')
            ->limit(5)
            ->get();

        $balance = (float) $accounts->sum('balance_ugx');
        $interestEarned = (float) $accounts->sum('accrued_interest_ugx');
        $interestRate = $accounts->count() > 0
            ? round((float) $accounts->avg('interest_rate'), 2)
            : 0;

        $thisMonthDeposits = (float) SaccoSavingsTransaction::query()
            ->where('member_id', $member->id)
            ->where('type', 'deposit')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount_ugx');

        $lastDeposit = SaccoSavingsTransaction::query()
            ->where('member_id', $member->id)
            ->where('type', 'deposit')
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'accounts' => SaccoSavingsAccountResource::collection($accounts)->resolve(),
                'balance' => $balance,
                'interest_earned' => $interestEarned,
                'interest_rate' => $interestRate,
                'this_month' => $thisMonthDeposits,
                'last_deposit' => optional($lastDeposit?->created_at)->toIso8601String(),
                'goals' => $goals->map(fn ($goal) => [
                    'id' => $goal->id,
                    'name' => $goal->title,
                    'target' => (float) $goal->target_amount,
                    'current' => (float) $goal->current_amount,
                    'deadline' => optional($goal->deadline)->toDateString(),
                ])->values(),
            ],
        ]);
    }

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
            'account_id' => 'nullable|integer|exists:sacco_savings_accounts,id',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:500',
            'reference_number' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:20',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $account = isset($validated['account_id'])
            ? SaccoSavingsAccount::findOrFail($validated['account_id'])
            : $this->resolveDefaultAccount($request, true);

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
            'account_id' => 'nullable|integer|exists:sacco_savings_accounts,id',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:500',
            'reference_number' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:20',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $account = isset($validated['account_id'])
            ? SaccoSavingsAccount::findOrFail($validated['account_id'])
            : $this->resolveDefaultAccount($request, false);

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
     * GET /api/sacco/transactions — consolidated member savings transactions
     */
    public function memberTransactions(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);
        $perPage = min((int) $request->input('per_page', $request->input('limit', 15)), 100);

        $query = SaccoSavingsTransaction::query()
            ->where('member_id', $member->id)
            ->latest();

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        $transactions = $query->paginate($perPage);

        return response()->json([
            'data' => SaccoTransactionResource::collection($transactions->getCollection())->resolve(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
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

    private function getAuthenticatedMember(Request $request): SaccoMember
    {
        return SaccoMember::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function resolveDefaultAccount(Request $request, bool $createIfMissing): SaccoSavingsAccount
    {
        $member = $this->getAuthenticatedMember($request);

        $account = SaccoSavingsAccount::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($account) {
            return $account;
        }

        if (! $createIfMissing) {
            throw ValidationException::withMessages([
                'account_id' => ['No active savings account found for this member.'],
            ]);
        }

        return SaccoSavingsAccount::create([
            'member_id' => $member->id,
            'account_type' => 'regular',
            'account_name' => 'Main Savings',
            'interest_rate' => 0,
            'minimum_balance_ugx' => 0,
            'status' => 'active',
        ]);
    }
}
