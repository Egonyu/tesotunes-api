<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\CreditMilestone;
use App\Services\Credits\CreditMilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Credit goals, member facing.
 */
class CreditMilestoneController extends Controller
{
    public function __construct(private readonly CreditMilestoneService $milestones) {}

    /**
     * GET /api/credits/goals
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $earned = $this->milestones->activityCredits($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'activity_credits' => $earned,
                    'milestones' => $this->milestones->milestonesFor($user, $earned),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('credits.goals.index_failed', ['user_id' => $request->user()?->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Could not load credit goals.'], 500);
        }
    }

    /**
     * POST /api/credits/goals/{milestone}/claim
     */
    public function claim(Request $request, int $milestone): JsonResponse
    {
        $target = CreditMilestone::active()->findOrFail($milestone);

        try {
            $claim = $this->milestones->claim($request->user(), $target);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('credits.goals.claim_failed', [
                'user_id' => $request->user()?->id,
                'milestone_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Could not claim this goal. Please try again.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "{$target->name} claimed. {$claim->credits_awarded} credits added.",
            'data' => [
                'milestone_id' => $target->id,
                'credits_awarded' => $claim->credits_awarded,
                'badge_name' => (string) $target->badge_name,
                'claimed_at' => $claim->claimed_at->toIso8601String(),
            ],
        ]);
    }
}
