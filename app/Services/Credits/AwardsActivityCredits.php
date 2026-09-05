<?php

namespace App\Services\Credits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Award an activity's credits from wherever that activity happens.
 *
 * The platform already had a home for this — an AwardCreditsForActivity
 * listener wired to SongLiked, CommentCreated, UserLoggedIn and friends. None
 * of those events were ever written and the listener was never registered, so
 * ten of the twelve rates on /admin/rewards had never paid a single credit
 * since the feature shipped. Rather than write seven event classes to carry a
 * value between two points in the same request, the award goes in where the
 * action completes.
 *
 * Everything routes through RewardRuleService, so the daily limits, cooldowns
 * and lifetime caps configured in /admin/rewards actually apply. The older
 * CreditService path enforces none of them, which is how listen_earn and the
 * welcome bonus came to be ungoverned.
 *
 * Awarding never breaks the action that earned it: a failure here is logged
 * and swallowed. Someone liking a song should not see an error because the
 * credit ledger had a bad moment.
 */
trait AwardsActivityCredits
{
    protected function awardActivityCredits(
        ?User $user,
        string $activity,
        ?Model $subject = null,
        array $metadata = [],
    ): void {
        if (! $user) {
            return;
        }

        try {
            app(RewardRuleService::class)->award($user, $activity, [
                'sourceable' => $subject,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('credits.activity_award_failed', [
                'user_id' => $user->id,
                'activity' => $activity,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
