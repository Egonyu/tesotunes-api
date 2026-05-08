<?php

namespace App\Modules\Sacco\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoLoan;
use App\Models\Sacco\SaccoMember;
use App\Models\Sacco\SaccoSavingsAccount;
use App\Models\Sacco\SaccoSavingsTransaction;
use App\Models\Sacco\SaccoShare;
use App\Models\Sacco\SaccoShareTransaction;
use App\Modules\Sacco\Http\Resources\SaccoMemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaccoMembershipController extends Controller
{
    /**
     * GET /api/sacco/membership — current user's membership
     */
    public function myMembership(Request $request): JsonResponse
    {
        $member = SaccoMember::with(['user:id,username,email', 'savingsAccounts', 'shares'])
            ->withCount('loans')
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $member) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => new SaccoMemberResource($member),
        ]);
    }

    /**
     * GET /api/sacco/dashboard — member-facing SACCO summary
     */
    public function dashboard(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);

        $member->loadMissing(['shares', 'activeLoan']);

        $savingsAccounts = SaccoSavingsAccount::query()
            ->where('member_id', $member->id)
            ->get();

        $savingsBalance = (float) $savingsAccounts->sum('balance_ugx');
        $share = $member->shares;
        $thisMonthDeposits = (float) SaccoSavingsTransaction::query()
            ->where('member_id', $member->id)
            ->where('type', 'deposit')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount_ugx');

        $activeLoanCount = SaccoLoan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['approved', 'disbursed', 'active'])
            ->count();

        $totalBorrowed = (float) SaccoLoan::query()
            ->where('member_id', $member->id)
            ->sum('principal_amount_ugx');

        $totalPaid = (float) SaccoLoan::query()
            ->where('member_id', $member->id)
            ->sum('amount_paid_ugx');

        $outstanding = (float) SaccoLoan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['approved', 'disbursed', 'active'])
            ->sum('balance_remaining_ugx');

        return response()->json([
            'data' => [
                'member' => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'status' => $member->status,
                    'credit_score' => $member->credit_score,
                    'joined_at' => optional($member->joined_at)->toDateString(),
                ],
                'accounts' => [
                    'savings' => $savingsBalance,
                    'shares' => (float) optional($share)->total_value_ugx,
                    'fixed_deposits' => (float) $savingsAccounts->where('account_type', 'fixed_deposit')->sum('balance_ugx'),
                ],
                'loans' => [
                    'active_count' => $activeLoanCount,
                    'total_borrowed' => $totalBorrowed,
                    'total_outstanding' => $outstanding,
                    'total_paid' => $totalPaid,
                ],
                'transactions' => [
                    'today' => SaccoSavingsTransaction::query()
                        ->where('member_id', $member->id)
                        ->whereDate('created_at', today())
                        ->count(),
                    'this_month' => $thisMonthDeposits,
                    'total_volume' => (float) SaccoSavingsTransaction::query()
                        ->where('member_id', $member->id)
                        ->sum('amount_ugx'),
                ],
                'dividends' => [
                    'total_earned' => 0,
                    'pending' => 0,
                ],
            ],
        ]);
    }

    /**
     * GET /api/sacco/profile — current user's profile details
     */
    public function profile(Request $request): JsonResponse
    {
        $member = $this->getAuthenticatedMember($request);
        $member->loadMissing('user:id,username,email,phone');

        return response()->json([
            'data' => [
                'id' => $member->id,
                'user' => [
                    'id' => $member->user?->id,
                    'name' => $member->user?->username ?? $member->user?->name,
                    'email' => $member->user?->email,
                    'phone' => $member->phone_number ?? $member->user?->phone,
                ],
                'member_number' => $member->member_number,
                'status' => $member->status,
                'credit_score' => $member->credit_score,
                'joined_at' => optional($member->joined_at)->toDateString(),
                'national_id' => $member->national_id ?? $member->id_number,
                'phone_number' => $member->phone_number ?? $member->user?->phone,
                'date_of_birth' => optional($member->date_of_birth)->toDateString(),
                'address' => $member->address,
                'occupation' => $member->occupation,
                'employer' => $member->employer,
                'monthly_income' => $member->monthly_income,
                'next_of_kin' => [
                    'name' => $member->next_of_kin_name ?? $member->emergency_contact_name,
                    'phone' => $member->next_of_kin_phone ?? $member->emergency_contact_phone,
                    'relationship' => $member->next_of_kin_relationship,
                ],
            ],
        ]);
    }

    /**
     * POST /api/sacco/join — self-registration for authenticated user
     */
    public function join(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if already a member
        if (SaccoMember::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'You are already a SACCO member.'], 422);
        }

        $validated = $request->validate([
            'initial_deposit' => 'nullable|numeric|min:0',
            'initial_shares' => 'nullable|integer|min:0',
            'payment_method' => 'nullable|string|in:wallet,zengapay',
        ]);

        $initialDeposit = (float) ($validated['initial_deposit'] ?? 0);
        $initialShares = (int) ($validated['initial_shares'] ?? 0);
        $shareValue = (int) config('sacco.share_capital.share_value', 10000);
        $shareAmount = $initialShares * $shareValue;
        $totalAmount = $initialDeposit + $shareAmount;

        if ($totalAmount > 0 && ($user->ugx_balance ?? 0) < $totalAmount) {
            throw ValidationException::withMessages([
                'initial_deposit' => ['Insufficient wallet balance for the opening contribution.'],
            ]);
        }

        $member = DB::transaction(function () use ($user, $initialDeposit, $initialShares, $shareValue, $shareAmount, $totalAmount) {
            if ($totalAmount > 0) {
                $user->decrement('ugx_balance', $totalAmount);
            }

            $member = SaccoMember::create([
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
                'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
            ]);

            if ($initialDeposit > 0) {
                $account = SaccoSavingsAccount::create([
                    'member_id' => $member->id,
                    'account_type' => 'regular',
                    'account_name' => 'Main Savings',
                    'interest_rate' => 0,
                    'minimum_balance_ugx' => 0,
                    'status' => 'active',
                    'balance_ugx' => $initialDeposit,
                ]);

                $member->increment('total_savings', $initialDeposit);

                SaccoSavingsTransaction::create([
                    'account_id' => $account->id,
                    'member_id' => $member->id,
                    'type' => 'deposit',
                    'amount_ugx' => $initialDeposit,
                    'balance_before_ugx' => 0,
                    'balance_after_ugx' => $initialDeposit,
                    'description' => 'Opening savings deposit',
                    'status' => 'completed',
                    'payment_method' => 'wallet',
                ]);
            }

            if ($initialShares > 0) {
                $share = SaccoShare::create([
                    'member_id' => $member->id,
                    'total_shares' => $initialShares,
                    'share_value_ugx' => $shareValue,
                    'total_value_ugx' => $shareAmount,
                    'last_purchase_at' => now(),
                ]);

                $member->increment('total_shares', $shareAmount);

                SaccoShareTransaction::create([
                    'member_id' => $member->id,
                    'share_id' => $share->id,
                    'type' => 'purchase',
                    'shares_quantity' => $initialShares,
                    'price_per_share_ugx' => $shareValue,
                    'total_amount_ugx' => $shareAmount,
                    'status' => 'completed',
                    'notes' => 'Opening share purchase',
                ]);
            }

            return $member;
        });

        $member->load('user:id,username,email');

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Welcome to TesoTunes SACCO!',
        ], 201);
    }

    /**
     * GET /api/sacco/members — list members
     */
    public function index(Request $request)
    {
        $query = SaccoMember::with('user:id,username,email');

        if ($search = $request->get('search')) {
            $escaped = escape_like($search);
            $query->where(function ($q) use ($escaped) {
                $q->where('member_number', 'like', "%{$escaped}%")
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$escaped}%")->orWhere('email', 'like', "%{$escaped}%"));
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $members = $query->latest('joined_at')
            ->paginate($this->getPerPage($request));

        return SaccoMemberResource::collection($members);
    }

    /**
     * POST /api/sacco/members — register member
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id|unique:sacco_members,user_id',
            'member_type' => 'nullable|string|in:regular,premium',
            'id_number' => 'nullable|string|max:50',
            'id_type' => 'nullable|string|in:national_id,passport,driving_permit',
            'date_of_birth' => 'nullable|date|before:today',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        $member = SaccoMember::create([
            ...$validated,
            'status' => 'active',
            'joined_at' => now(),
            'member_number' => 'MBR'.now()->format('Ymd').rand(10000, 99999),
        ]);

        $member->load('user:id,username,email');

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Member registered successfully.',
        ], 201);
    }

    /**
     * GET /api/sacco/members/{member} — member detail
     */
    public function show($member)
    {
        $member = SaccoMember::with(['user:id,username,email', 'savingsAccounts', 'shares'])
            ->withCount('loans')
            ->findOrFail($member);

        return new SaccoMemberResource($member);
    }

    /**
     * PUT /api/sacco/members/{member} — update member
     */
    public function update(Request $request, $member): JsonResponse
    {
        $member = SaccoMember::findOrFail($member);

        $validated = $request->validate([
            'member_type' => 'nullable|string|in:regular,premium',
            'id_number' => 'nullable|string|max:50',
            'id_type' => 'nullable|string|in:national_id,passport,driving_permit',
            'date_of_birth' => 'nullable|date|before:today',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        $member->update(array_filter($validated, fn ($v) => $v !== null));
        $member->load('user:id,username,email');

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Member updated successfully.',
        ]);
    }

    /**
     * PATCH /api/sacco/members/{member}/status — update status
     */
    public function updateStatus(Request $request, $member): JsonResponse
    {
        $member = SaccoMember::findOrFail($member);

        $validated = $request->validate([
            'status' => 'required|string|in:active,suspended,resigned,deceased',
        ]);

        $member->update(['status' => $validated['status']]);

        return response()->json([
            'data' => new SaccoMemberResource($member),
            'message' => 'Member status updated to '.$validated['status'].'.',
        ]);
    }

    private function getAuthenticatedMember(Request $request): SaccoMember
    {
        return SaccoMember::with(['user:id,username,email,phone'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
