<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Promotions Module — Master Toggle
    |--------------------------------------------------------------------------
    */
    'enabled' => env('PROMOTIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Platform Fee Rates
    |--------------------------------------------------------------------------
    | Applied to the gross transaction value for each currency leg.
    | UGX fee is tiered by promoter store subscription (see Store model).
    | Credits fee is flat-rate (no store-tier benefit on credits).
    */
    'platform_fee_credits_rate' => env('PROMOTIONS_CREDITS_FEE_RATE', 0.15),
    'platform_fee_ugx_rate' => env('PROMOTIONS_UGX_FEE_RATE', 0.10),

    /*
    |--------------------------------------------------------------------------
    | Workflow Timing
    |--------------------------------------------------------------------------
    */
    'dispute_window_hours' => env('PROMOTIONS_DISPUTE_WINDOW_HOURS', 72),
    'auto_release_hours' => env('PROMOTIONS_AUTO_RELEASE_HOURS', 168),  // 7 days
    'application_ttl_days' => env('PROMOTIONS_APPLICATION_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Opportunity Limits
    |--------------------------------------------------------------------------
    */
    'max_open_opportunities_per_user' => 10,
    'max_applications_per_opportunity' => 50,

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    | Auto-provision a store for non-artist promoters during onboarding.
    */
    'auto_provision_store' => true,
    'default_store_type' => 'promoter',
    'default_store_tier' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Feature Flag — V2 Dual-Write Window
    |--------------------------------------------------------------------------
    | While true, new opportunities also write back to stores.metadata for
    | backward compat with the old PromotionController browse endpoint.
    | Set to false after frontend cutover is complete.
    */
    'dual_write_enabled' => env('PROMOTIONS_DUAL_WRITE', true),

    /*
    |--------------------------------------------------------------------------
    | Promoter Tier Thresholds
    |--------------------------------------------------------------------------
    */
    'tiers' => [
        'starter' => ['min_completed' => 0,   'min_rating' => 0.0],
        'rising' => ['min_completed' => 5,   'min_rating' => 3.5],
        'established' => ['min_completed' => 20,  'min_rating' => 4.0],
        'elite' => ['min_completed' => 50,  'min_rating' => 4.5],
    ],
];
