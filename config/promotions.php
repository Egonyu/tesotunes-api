<?php

/*
|--------------------------------------------------------------------------
| Promoter Market
|--------------------------------------------------------------------------
|
| Every key below is read by code. Keys that described behaviour nothing
| implemented were removed rather than left to imply the behaviour exists:
|
|   enabled              The feature toggle is the `general_promotions_enabled`
|                        setting (App\Settings\Definitions\FeatureSettings),
|                        which is what the app and the admin surface actually
|                        read. Nothing consulted this key.
|   platform_fee_ugx_rate
|                        The UGX fee is tiered per store subscription and comes
|                        from config('store.fees.promotion_*_tier') via
|                        Store::calculatePromotionFee(). This key was a second,
|                        unread copy that happened to share the free-tier value.
|   dual_write_enabled   Described writing new opportunities back to
|                        stores.metadata for the V1 browse endpoint. No such
|                        write was ever implemented.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Platform Fee Rate — credits
    |--------------------------------------------------------------------------
    | Applied to the gross credits leg of a promotion settlement. Flat-rate:
    | store subscription tiers discount the UGX leg only.
    | Read by App\Services\Store\PromotionSettlementService.
    */
    'platform_fee_credits_rate' => env('PROMOTIONS_CREDITS_FEE_RATE', 0.15),

    /*
    |--------------------------------------------------------------------------
    | Escrow Auto-Release
    |--------------------------------------------------------------------------
    | How long submitted proof waits for the seller before the platform
    | releases payment on their behalf. Read by the hourly
    | promotions:release-due-escrow command; orders with an open dispute or
    | without submitted proof are never released.
    |
    | This supersedes the unread store.php 'auto_release_days' => 7, which
    | should be removed when the store module's own escrow is reviewed.
    */
    'auto_release_hours' => env('PROMOTIONS_AUTO_RELEASE_HOURS', 168),  // 7 days

    /*
    |--------------------------------------------------------------------------
    | Workflow Timing — NOT YET ENFORCED
    |--------------------------------------------------------------------------
    | These record intended policy that nothing reads yet: a dispute can still
    | be filed at any time, including after settlement, and applications never
    | expire.
    */
    'dispute_window_hours' => env('PROMOTIONS_DISPUTE_WINDOW_HOURS', 72),
    'application_ttl_days' => env('PROMOTIONS_APPLICATION_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Opportunity Limits — NOT YET ENFORCED
    |--------------------------------------------------------------------------
    | Intended anti-spam ceilings. No check consults them today, so a user may
    | post unlimited briefs and a brief may take unlimited applications.
    */
    'max_open_opportunities_per_user' => 10,
    'max_applications_per_opportunity' => 50,

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    | Auto-provision a store for non-artist promoters during onboarding.
    | Read by App\Modules\Promotions\Services\PromoterOnboardingService.
    */
    'auto_provision_store' => true,
    'default_store_type' => 'promoter',
    'default_store_tier' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Promoter Tier Thresholds
    |--------------------------------------------------------------------------
    | Read by App\Modules\Promotions\Models\PromoterProfile::recalculateTier().
    */
    'tiers' => [
        'starter' => ['min_completed' => 0,   'min_rating' => 0.0],
        'rising' => ['min_completed' => 5,   'min_rating' => 3.5],
        'established' => ['min_completed' => 20,  'min_rating' => 4.0],
        'elite' => ['min_completed' => 50,  'min_rating' => 4.5],
    ],
];
