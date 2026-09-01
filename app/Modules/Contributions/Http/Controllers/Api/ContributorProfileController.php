<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Contributions\Models\ContributionSubmission;
use App\Modules\Contributions\Models\ContributorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The contributor's own standing: tier, gold pass-rate, running totals, and
 * credits earned from accepted work.
 */
class ContributorProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = ContributorProfile::query()->where('user_id', $user->id)->first();

        // Work submitted but not yet settled (awaiting peer acceptance). Surfaced
        // so the client can show a *persistent* "pending review" figure instead of
        // a volatile in-session tally that resets on refresh.
        $submissionsPending = ContributionSubmission::query()
            ->where('user_id', $user->id)
            ->where('settled', false)
            ->where('status', ContributionSubmission::STATUS_SUBMITTED)
            ->count();

        $perPair = (int) config('contributions.rewards.per_pair_ugx', 200);

        return response()->json([
            'success' => true,
            'data' => $profile ? [
                'tier' => $profile->tier,
                'gold_pass_rate' => (float) $profile->gold_pass_rate,
                'gold_attempts' => $profile->gold_attempts,
                'submissions_total' => $profile->submissions_total,
                'submissions_accepted' => $profile->submissions_accepted,
                'validations_total' => $profile->validations_total,
                'credits_earned_total' => $profile->credits_earned_total,
                'submissions_pending' => $submissionsPending,
                'pending_estimate_credits' => $submissionsPending * $perPair,
                'consented' => $profile->hasConsented(),
            ] : null,
        ]);
    }
}
