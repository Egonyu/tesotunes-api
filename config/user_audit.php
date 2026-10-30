<?php

/*
 * Admin "User 360" audit view.
 *
 * Flag thresholds are operator tuning, not logic: change a number here to make a
 * rule stricter or looser. Weights decide how much each severity adds to the
 * 0-100 risk score shown beside a user.
 */
return [
    'thresholds' => [
        // KYC approved faster than this after submission is flagged as a rubber-stamp.
        'kyc_fast_approval_minutes' => 5,
        // First withdrawal this soon after KYC approval is flagged.
        'withdrawal_after_kyc_minutes' => 15,
        // Share of earned credits cashed out that counts as "cash-out only".
        'cashout_ratio' => 0.9,
        'cashout_min_earned' => 10000,
        // Listen-to-earn: a payout at/above the cap for less listening than this is low effort.
        'listen_cap_credits' => 1000,
        'listen_low_effort_seconds' => 300,
        'listen_low_effort_min_days' => 3,
        'blocked_requests' => 20,
        'rate_limited_requests' => 10,
        'failed_withdrawals' => 2,
        // Share of referees with no login or no plays that counts as thin referrals.
        'inactive_referee_ratio' => 0.5,
        'inactive_referee_min' => 2,
        // Contributors with this many submissions and no gold-standard test are unverified.
        'contribution_min_for_gold' => 50,
    ],

    'weights' => [
        'critical' => 40,
        'high' => 25,
        'medium' => 10,
        'low' => 3,
    ],

    // The score is 100 * (1 - e^(-sum of weights / 80)). With the weights above:
    // one critical flag ~ 39, two high flags ~ 46, a dozen mixed flags ~ 76.
    // Below `medium` is low, from `medium` is medium, from `high` is high.
    'levels' => ['medium' => 20, 'high' => 45],

    'timeline' => [
        'default_per_page' => 50,
        'max_per_page' => 100,
        // Hidden unless include_noise=1 (token refreshes drown out real events).
        'noise_categories' => ['session'],
    ],
];
