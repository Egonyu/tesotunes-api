<?php

return [
    /*
    | Master switch. The module's provider early-exits when disabled, so routes
    | and services carry no overhead until we flip this on. Off until the
    | surfaces (9.2+) ship.
    */
    'enabled' => env('CONTRIBUTIONS_ENABLED', false),

    /*
    | The current data-terms version. Bump this string whenever the contributor
    | grant / corpus-release terms change; contributors are re-prompted to
    | re-consent when their recorded version is older than this.
    */
    'terms_version' => env('CONTRIBUTIONS_TERMS_VERSION', '2026-06-14'),

    // Public corpus release license (the contributor grant to TesoTunes is
    // broader; see docs/architecture/ATESO_DATA_PIPELINE.md).
    'license_version' => 'CC-BY-SA-4.0',

    'languages' => [
        'source' => 'en',
        'target' => 'teo', // ISO 639-3 for Ateso/Teso
    ],

    'default_region' => 'ug', // Ugandan Ateso

    // Independent translations gathered per task before agreement scoring.
    'redundancy_target' => 3,

    /*
    | Reward economics (consumed from 9.4 onward). Money rides the settlement
    | ledger; these are the tuning knobs. Conservative by design.
    */
    'rewards' => [
        'per_pair_ugx' => 200,
        'per_pair_floor_ugx' => 100,
        'validation_pct' => 0.50,       // validation pays 50% of a translation
        'trusted_multiplier' => 1.30,   // trusted-tier bonus
        'daily_pool_ugx' => 50000,      // start small; raise as quality proves out
        'per_contributor_daily_cap' => 20, // max rewarded accepted pairs/day
    ],

    'tiers' => [
        // Gold pass-rate (%) thresholds for promotion.
        'trusted_min_pass_rate' => 85,
        'reviewer_min_pass_rate' => 95,
        'min_gold_attempts' => 10,
    ],
];
