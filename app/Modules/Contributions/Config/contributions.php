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

    // Ateso varieties/dialects a contributor can tag their work with. Codes are
    // stable keys; labels are for the UI. Ateso is dialect-rich, so a "different"
    // answer is a tagged variant, not a wrong answer.
    'dialects' => [
        'katakwi' => 'Katakwi / Usuk',
        'amuria' => 'Amuria',
        'soroti' => 'Soroti',
        'serere' => 'Serere',
        'kumi' => 'Kumi',
        'ngora' => 'Ngora',
        'bukedea' => 'Bukedea',
        'pallisa' => 'Pallisa',
        'tororo' => 'Tororo',
        'kenya' => 'Kenya-Teso',
        'general' => 'Unsure / General',
    ],

    // Include code-switched (mixed-language) pairs in exports by default; they
    // can be filtered out for a "pure" corpus. Real usage is the point.
    'export_include_code_switched' => true,

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

    // Per-tier validator vote weight — trusted reviewers count for more, so the
    // quality gate concentrates on people who pass golds.
    'validation_weights' => [
        'novice' => 1.0,
        'trusted' => 1.5,
        'reviewer' => 2.0,
    ],

    'acceptance' => [
        // Minimum number of peer validations a submission needs before it can
        // be accepted, and the minimum weighted approval to clear the gate.
        'min_validations' => 2,
        'approval_threshold' => 2.0,
        // Bonus added to the agreement score for each independent submission that
        // normalizes to the same text (translator convergence).
        'convergence_bonus' => 10,
    ],

    // Edula feed: weave an "Earn" task card in after every N organic items.
    'feed' => [
        'enabled' => env('CONTRIBUTIONS_FEED_CARDS', true),
        'every' => 6,
        'max_per_page' => 2,
    ],

    /*
    | Daily challenge rotation — themed prompts targeting registers/domains the
    | corpus lacks. The scheduled command publishes one per day, picked by
    | day-of-year. Source is the lyric language (Ateso) → English by default.
    */
    'daily_challenges' => [
        ['register' => 'greeting', 'prompt' => 'Ijaarakini'],
        ['register' => 'market', 'prompt' => 'Ainap'],
        ['register' => 'family', 'prompt' => 'Toto'],
        ['register' => 'weather', 'prompt' => 'Akipi'],
        ['register' => 'proverb', 'prompt' => 'Emam ŋes itunga'],
    ],
];
