<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Commission
    |--------------------------------------------------------------------------
    |
    | Percentage of subscription revenue the platform keeps.
    |
    */
    'platform_commission_percentage' => env('LOYALTY_COMMISSION', 10),

    /*
    |--------------------------------------------------------------------------
    | Tier Levels
    |--------------------------------------------------------------------------
    |
    | Numeric hierarchy: higher number = more privileges.
    |
    */
    'tier_levels' => [
        'bronze' => 1,
        'silver' => 2,
        'gold' => 3,
        'platinum' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Points Earning Rates (base, before multiplier)
    |--------------------------------------------------------------------------
    */
    'points_earning' => [
        'stream' => 1,
        'download' => 5,
        'purchase_per_100_ugx' => 1,
        'event_attendance' => 10,
        'referral' => 50,
        'daily_login' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Points ↔ Credits Conversion
    |--------------------------------------------------------------------------
    */
    'points_to_credits_rate' => 10, // 100 points = 10 credits

    /*
    |--------------------------------------------------------------------------
    | Renewal Settings
    |--------------------------------------------------------------------------
    */
    'renewal_reminder_days' => 3,
    'grace_period_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Admin Approval
    |--------------------------------------------------------------------------
    |
    | When true new loyalty cards must be approved before becoming active.
    |
    */
    'requires_admin_approval' => env('LOYALTY_REQUIRES_APPROVAL', true),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'max_cards_per_artist' => 5,
    'max_tiers_per_card' => 4,
];
