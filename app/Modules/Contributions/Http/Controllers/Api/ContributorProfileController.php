<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $profile = ContributorProfile::query()->where('user_id', $request->user()->id)->first();

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
                'consented' => $profile->hasConsented(),
            ] : null,
        ]);
    }
}
