<?php

namespace Database\Seeders;

use App\Models\CreditRate;
use Illuminate\Database\Seeder;

/**
 * The starting price list for everything the platform pays people to do.
 *
 * These are defaults, not settings: an operator edits them in the admin, and
 * re-running this seeder will not overwrite those edits — rows are created only
 * where the activity has none. Nothing here is a constant in code, which is the
 * point. Marketing changes what an activity pays by changing the row, and a
 * time-boxed push is the same row with starts_at/ends_at filled in.
 *
 * Where production already paid a rate, the figure below matches it, so turning
 * the table on does not silently reprice work people have already been doing:
 * contribution_translation was paying 200 (800 credits over 4 awards) and
 * contribution_validation 100 (800 over 8).
 */
class CreditRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            // ── Growth. The reason the platform is worth marketing ──────────
            [
                'activity_type' => CreditRate::REFERRAL_SIGNUP,
                'display_name' => 'Referred a friend',
                'description' => 'Paid to the referrer when someone signs up with their code.',
                'credits_per_action' => 500,
                'daily_limit' => 5000,      // 10 referrals a day before it looks like farming
                'sort_order' => 1,
            ],
            [
                'activity_type' => CreditRate::REFERRAL_WELCOME,
                'display_name' => 'Joined with a friend’s code',
                'description' => 'Paid once to a new account that signed up with a referral code.',
                'credits_per_action' => 200,
                'max_per_user_lifetime' => 1,
                'sort_order' => 2,
            ],
            [
                'activity_type' => CreditRate::PROFILE_COMPLETE,
                'display_name' => 'Completed your profile',
                'description' => 'Paid once when a profile has a name, photo and country.',
                'credits_per_action' => 100,
                'max_per_user_lifetime' => 1,
                'sort_order' => 3,
            ],

            // ── Habit. Small, capped, daily ─────────────────────────────────
            [
                'activity_type' => CreditRate::DAILY_LOGIN,
                'display_name' => 'Daily login',
                'description' => 'Paid once a day for opening the app.',
                'credits_per_action' => 10,
                'daily_limit' => 10,
                'cooldown_minutes' => 1440,
                'sort_order' => 10,
            ],
            [
                'activity_type' => CreditRate::SONG_PLAY_COMPLETE,
                'display_name' => 'Finished a song',
                'description' => 'Paid per completed play, capped daily.',
                'credits_per_action' => 0.5,
                'daily_limit' => 50,
                'cooldown_minutes' => 1,
                'sort_order' => 11,
            ],

            // ── Participation ───────────────────────────────────────────────
            [
                'activity_type' => CreditRate::SOCIAL_LIKE,
                'display_name' => 'Liked something',
                'credits_per_action' => 1,
                'daily_limit' => 30,
                'sort_order' => 20,
            ],
            [
                'activity_type' => CreditRate::SOCIAL_SHARE,
                'display_name' => 'Shared something',
                'description' => 'Sharing reaches people outside the platform, so it pays more than a like.',
                'credits_per_action' => 2,
                'daily_limit' => 30,
                'sort_order' => 21,
            ],
            [
                'activity_type' => CreditRate::SOCIAL_COMMENT,
                'display_name' => 'Left a comment',
                'credits_per_action' => 2,
                'daily_limit' => 30,
                'sort_order' => 22,
            ],
            [
                'activity_type' => CreditRate::SOCIAL_FOLLOW,
                'display_name' => 'Followed an artist',
                'credits_per_action' => 1,
                'daily_limit' => 30,
                'sort_order' => 23,
            ],
            [
                'activity_type' => CreditRate::PLAYLIST_CREATE,
                'display_name' => 'Made a playlist',
                'credits_per_action' => 5,
                'daily_limit' => 25,
                'sort_order' => 24,
            ],

            // ── Corpus work. Already being paid at these rates ───────────────
            [
                'activity_type' => CreditRate::CONTRIBUTION_TRANSLATION,
                'display_name' => 'Translated a line',
                'description' => 'Ateso corpus contribution. Matches the rate already paid in production.',
                'credits_per_action' => 200,
                'sort_order' => 30,
            ],
            [
                'activity_type' => CreditRate::CONTRIBUTION_VALIDATION,
                'display_name' => 'Validated a translation',
                'description' => 'Ateso corpus review. Matches the rate already paid in production.',
                'credits_per_action' => 100,
                'sort_order' => 31,
            ],
        ];

        foreach ($rates as $rate) {
            // firstOrCreate, not updateOrCreate: an operator who has retuned a
            // rate should not have it reset by a deploy.
            CreditRate::firstOrCreate(
                ['activity_type' => $rate['activity_type']],
                $rate + ['is_active' => true],
            );
        }
    }
}
