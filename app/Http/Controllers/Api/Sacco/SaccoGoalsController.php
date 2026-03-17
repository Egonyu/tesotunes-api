<?php

namespace App\Http\Controllers\Api\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco\SaccoGoal;
use App\Models\Sacco\SaccoGoalTransaction;
use App\Models\Sacco\SaccoMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaccoGoalsController extends Controller
{
    /**
     * GET /api/sacco/goals — list goals for current member
     */
    public function index(Request $request): JsonResponse
    {
        $member = $this->getMember($request);

        $query = SaccoGoal::where('member_id', $member->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $goals = $query->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $goals->map(fn ($goal) => $this->formatGoal($goal)),
        ]);
    }

    /**
     * GET /api/sacco/goals/{id} — show a single goal
     */
    public function show(Request $request, $id): JsonResponse
    {
        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatGoal($goal),
        ]);
    }

    /**
     * POST /api/sacco/goals — create a new savings goal
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_amount' => 'required|numeric|min:1',
            'currency' => 'nullable|string|in:UGX,USD,credits',
            'deadline' => 'nullable|date|after:today',
            'visibility' => 'nullable|string|in:private,members,public',
            'monthly_target' => 'nullable|numeric|min:0',
            'auto_deposit' => 'nullable|boolean',
            'auto_deposit_percentage' => 'nullable|numeric|min:0|max:100',
            'credit_conversion_enabled' => 'nullable|boolean',
            'production_details' => 'nullable|array',
        ]);

        $member = $this->getMember($request);

        $goal = SaccoGoal::create([
            'member_id' => $member->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_amount' => $validated['target_amount'],
            'currency' => $validated['currency'] ?? 'UGX',
            'deadline' => $validated['deadline'] ?? null,
            'visibility' => $validated['visibility'] ?? 'private',
            'monthly_target' => $validated['monthly_target'] ?? null,
            'auto_deposit' => $validated['auto_deposit'] ?? false,
            'auto_deposit_percentage' => $validated['auto_deposit_percentage'] ?? null,
            'credit_conversion_enabled' => $validated['credit_conversion_enabled'] ?? false,
            'production_details' => $validated['production_details'] ?? null,
        ]);

        return response()->json([
            'data' => $this->formatGoal($goal),
            'message' => 'Savings goal created successfully.',
        ], 201);
    }

    /**
     * PUT /api/sacco/goals/{id} — update a goal
     */
    public function update(Request $request, $id): JsonResponse
    {
        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'type' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'target_amount' => 'nullable|numeric|min:1',
            'currency' => 'nullable|string|in:UGX,USD,credits',
            'deadline' => 'nullable|date',
            'visibility' => 'nullable|string|in:private,members,public',
            'monthly_target' => 'nullable|numeric|min:0',
            'auto_deposit' => 'nullable|boolean',
            'auto_deposit_percentage' => 'nullable|numeric|min:0|max:100',
            'credit_conversion_enabled' => 'nullable|boolean',
            'production_details' => 'nullable|array',
            'status' => 'nullable|string|in:active,paused,completed,cancelled',
        ]);

        $goal->update(array_filter($validated, fn ($value) => $value !== null));

        return response()->json([
            'data' => $this->formatGoal($goal->fresh()),
            'message' => 'Goal updated successfully.',
        ]);
    }

    /**
     * DELETE /api/sacco/goals/{id} — delete a goal
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->findOrFail($id);

        if ($goal->current_amount > 0) {
            return response()->json([
                'message' => 'Cannot delete a goal with existing funds. Please withdraw first.',
            ], 422);
        }

        $goal->delete();

        return response()->json([
            'message' => 'Goal deleted successfully.',
        ]);
    }

    /**
     * POST /api/sacco/goals/{id}/deposit — deposit funds into a goal
     */
    public function deposit(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'nullable|string|in:wallet',
        ]);

        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->where('status', 'active')
            ->findOrFail($id);

        return DB::transaction(function () use ($goal, $member, $validated, $request) {
            $user = $request->user();
            if (($user->ugx_balance ?? 0) < $validated['amount']) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient wallet balance.'],
                ]);
            }

            $user->decrement('ugx_balance', $validated['amount']);

            $balanceBefore = $goal->current_amount;
            $goal->increment('current_amount', $validated['amount']);
            $balanceAfter = $goal->fresh()->current_amount;

            $reference = 'GD-'.strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 10));

            SaccoGoalTransaction::create([
                'goal_id' => $goal->id,
                'member_id' => $member->id,
                'type' => 'deposit',
                'amount' => $validated['amount'],
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'payment_method' => $validated['payment_method'] ?? 'wallet',
                'transaction_reference' => $reference,
                'status' => 'completed',
            ]);

            // Auto-complete goal if target reached
            if ($goal->fresh()->current_amount >= $goal->target_amount) {
                $goal->update(['status' => 'completed']);
            }

            return response()->json([
                'data' => [
                    'reference' => $reference,
                    'status' => 'completed',
                    'amount' => $validated['amount'],
                    'new_balance' => $balanceAfter,
                ],
                'message' => 'Deposit successful.',
            ]);
        });
    }

    /**
     * POST /api/sacco/goals/{id}/convert-credits — convert credits to goal funds
     */
    public function convertCredits(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->where('status', 'active')
            ->findOrFail($id);

        $user = $request->user();
        if ((int) $user->credits < (int) $validated['amount']) {
            return response()->json([
                'message' => 'Insufficient credits balance.',
            ], 422);
        }

        $ugxValue = credits_to_ugx($validated['amount']);

        return DB::transaction(function () use ($goal, $member, $validated, $ugxValue, $user) {
            $transaction = $user->spendCredits(
                (int) $validated['amount'],
                'goal_conversion',
                "Converted credits into savings goal {$goal->title}",
                ['goal_id' => $goal->id]
            );

            if (! $transaction) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient credits balance.'],
                ]);
            }

            $balanceBefore = $goal->current_amount;
            $goal->increment('current_amount', $ugxValue);
            $balanceAfter = $goal->fresh()->current_amount;

            SaccoGoalTransaction::create([
                'goal_id' => $goal->id,
                'member_id' => $member->id,
                'type' => 'credit_conversion',
                'amount' => $ugxValue,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'payment_method' => 'credits',
                'notes' => "Converted {$validated['amount']} credits",
                'status' => 'completed',
            ]);

            return response()->json([
                'data' => [
                    'converted' => $validated['amount'],
                    'ugx_value' => $ugxValue,
                ],
                'message' => 'Credits converted successfully.',
            ]);
        });
    }

    /**
     * POST /api/sacco/goals/{id}/auto-save — update auto-save settings
     */
    public function autoSave(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'auto_deposit' => 'required|boolean',
            'auto_deposit_percentage' => 'nullable|numeric|min:0|max:100',
            'credit_conversion_enabled' => 'nullable|boolean',
        ]);

        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->findOrFail($id);

        $goal->update($validated);

        return response()->json([
            'data' => $this->formatGoal($goal->fresh()),
            'message' => 'Auto-save settings updated.',
        ]);
    }

    /**
     * GET /api/sacco/goals/{id}/transactions — list transactions for a goal
     */
    public function transactions(Request $request, $id): JsonResponse
    {
        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->findOrFail($id);

        $perPage = min((int) $request->input('per_page', 15), 100);

        $transactions = SaccoGoalTransaction::where('goal_id', $goal->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * GET /api/sacco/goals/{id}/funding-options — get funding options for a goal
     */
    public function fundingOptions(Request $request, $id): JsonResponse
    {
        $member = $this->getMember($request);

        $goal = SaccoGoal::where('member_id', $member->id)
            ->findOrFail($id);

        $remaining = $goal->remaining_amount;

        return response()->json([
            'data' => [
                'loan_eligible' => $remaining >= 50000,
                'loan_amount' => min($remaining, 5000000),
                'co_funding_available' => $goal->visibility !== 'private',
                'crowdfunding_enabled' => $goal->visibility === 'public',
                'current_tier' => null,
                'next_tier' => null,
            ],
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function getMember(Request $request): SaccoMember
    {
        // Middleware merges sacco_member onto the request
        if ($request->has('sacco_member')) {
            return $request->input('sacco_member');
        }

        return SaccoMember::where('user_id', auth()->id())
            ->where('status', 'active')
            ->firstOrFail();
    }

    private function formatGoal(SaccoGoal $goal): array
    {
        return [
            'id' => $goal->id,
            'uuid' => $goal->uuid,
            'type' => $goal->type,
            'title' => $goal->title,
            'description' => $goal->description,
            'target_amount' => (float) $goal->target_amount,
            'current_amount' => (float) $goal->current_amount,
            'currency' => $goal->currency,
            'deadline' => $goal->deadline?->toDateString(),
            'status' => $goal->status,
            'visibility' => $goal->visibility,
            'production_details' => $goal->production_details,
            'strategy' => [
                'monthly_target' => (float) ($goal->monthly_target ?? 0),
                'auto_deposit' => (bool) $goal->auto_deposit,
                'auto_deposit_percentage' => (float) ($goal->auto_deposit_percentage ?? 0),
                'credit_conversion_enabled' => (bool) $goal->credit_conversion_enabled,
            ],
            'progress' => [
                'percentage' => $goal->progress_percentage,
                'remaining_amount' => $goal->remaining_amount,
                'days_remaining' => $goal->days_remaining,
                'is_completed' => $goal->is_completed,
                'on_track' => $goal->days_remaining === null
                    ? true
                    : $goal->progress_percentage >= $this->expectedProgressForGoal($goal),
            ],
            'funding_options' => [
                'loan_eligible' => $goal->remaining_amount >= 50000,
                'loan_amount' => min($goal->remaining_amount, 5000000),
                'co_funding_available' => $goal->visibility !== 'private',
                'crowdfunding_enabled' => $goal->visibility === 'public',
                'current_tier' => null,
                'next_tier' => null,
            ],
            'created_at' => $goal->created_at?->toISOString(),
            'updated_at' => $goal->updated_at?->toISOString(),
        ];
    }

    private function expectedProgressForGoal(SaccoGoal $goal): float
    {
        if (! $goal->deadline || ! $goal->created_at || $goal->deadline->lessThanOrEqualTo($goal->created_at)) {
            return 0;
        }

        $totalWindow = max(1, $goal->created_at->diffInDays($goal->deadline));
        $elapsed = max(0, $goal->created_at->diffInDays(now(), false));

        return min(100, round(($elapsed / $totalWindow) * 100, 2));
    }
}
