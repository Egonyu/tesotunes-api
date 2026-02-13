<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Module Configuration
    |--------------------------------------------------------------------------
    |
    | This file manages the enable/disable state and settings for optional
    | platform modules. Each module can be toggled independently.
    |
    */

    'forum' => [
        /*
        |--------------------------------------------------------------------------
        | Forum Module Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable the community forum module.
        | When disabled, all forum routes return 404.
        |
        */
        'enabled' => env('FORUM_MODULE_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Require Post Approval
        |--------------------------------------------------------------------------
        |
        | When enabled, new topics/replies require moderator approval.
        | When disabled, posts are automatically published.
        |
        */
        'require_approval' => env('FORUM_REQUIRE_APPROVAL', false),

        /*
        |--------------------------------------------------------------------------
        | Maximum Topics Per Day
        |--------------------------------------------------------------------------
        |
        | Limit how many topics a user can create per day.
        | Set to 0 for unlimited.
        |
        */
        'max_topics_per_day' => (int) env('FORUM_MAX_TOPICS_PER_DAY', 10),
    ],

    'polls' => [
        /*
        |--------------------------------------------------------------------------
        | Polls Module Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable the polls/voting module.
        | When disabled, all poll routes return 404.
        |
        */
        'enabled' => env('POLLS_MODULE_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Allow Anonymous Voting
        |--------------------------------------------------------------------------
        |
        | When enabled, poll creators can choose to allow anonymous voting.
        | When disabled, all votes are attributed to users.
        |
        */
        'allow_anonymous' => env('POLLS_ALLOW_ANONYMOUS', true),

        /*
        |--------------------------------------------------------------------------
        | Maximum Polls Per Day
        |--------------------------------------------------------------------------
        |
        | Limit how many polls a user can create per day.
        | Set to 0 for unlimited.
        |
        */
        'max_polls_per_day' => (int) env('POLLS_MAX_POLLS_PER_DAY', 5),
    ],

    'podcast' => [
        /*
        |--------------------------------------------------------------------------
        | Podcast Module Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable the podcast module.
        |
        */
        'enabled' => env('PODCAST_MODULE_ENABLED', true),
    ],

    'store' => [
        /*
        |--------------------------------------------------------------------------
        | Store Module Enabled
        |--------------------------------------------------------------------------
        |
        | Enable or disable the entire store/ecommerce module.
        | When disabled, all store routes return 404.
        |
        */
        'enabled' => env('STORE_MODULE_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Artists Only Mode
        |--------------------------------------------------------------------------
        |
        | When enabled, only users with 'artist' role can create stores.
        | When disabled, all authenticated users can create stores.
        |
        */
        'artists_only' => env('STORE_ARTISTS_ONLY', false),

        /*
        |--------------------------------------------------------------------------
        | Require Store Approval
        |--------------------------------------------------------------------------
        |
        | When enabled, new stores require admin approval before going live.
        | When disabled, stores are automatically active after creation.
        |
        */
        'require_approval' => env('STORE_REQUIRE_APPROVAL', true),

        /*
        |--------------------------------------------------------------------------
        | Product Approval
        |--------------------------------------------------------------------------
        |
        | When enabled, new products require admin review before being listed.
        |
        */
        'require_product_approval' => env('STORE_REQUIRE_PRODUCT_APPROVAL', false),

        /*
        |--------------------------------------------------------------------------
        | Commission Rates
        |--------------------------------------------------------------------------
        |
        | Platform commission percentage on sales.
        | Supports different rates for different payment methods.
        |
        */
        'commission' => [
            'mobile_money' => (float) env('STORE_COMMISSION_MOBILE', 5.0), // 5%
            'credit' => (float) env('STORE_COMMISSION_CREDIT', 2.0),      // 2%
            'bank_transfer' => (float) env('STORE_COMMISSION_BANK', 3.0), // 3%
        ],

        /*
        |--------------------------------------------------------------------------
        | Maximum Stores Per User
        |--------------------------------------------------------------------------
        |
        | Limit how many stores a user can create.
        | Set to 0 for unlimited.
        |
        */
        'max_stores_per_user' => (int) env('STORE_MAX_STORES_PER_USER', 3),

        /*
        |--------------------------------------------------------------------------
        | Maximum Products Per Store
        |--------------------------------------------------------------------------
        |
        | Limit how many products a store can list.
        | Set to 0 for unlimited.
        |
        */
        'max_products_per_store' => (int) env('STORE_MAX_PRODUCTS_PER_STORE', 0),

        /*
        |--------------------------------------------------------------------------
        | Shipping Settings
        |--------------------------------------------------------------------------
        |
        | Default shipping configurations
        |
        */
        'shipping' => [
            'enabled' => env('STORE_SHIPPING_ENABLED', true),
            'regions' => [
                'Central' => ['Kampala', 'Wakiso', 'Mukono'],
                'Eastern' => ['Jinja', 'Mbale', 'Soroti'],
                'Western' => ['Mbarara', 'Fort Portal', 'Kasese'],
                'Northern' => ['Gulu', 'Lira', 'Arua'],
            ],
            'default_fee' => (int) env('STORE_SHIPPING_DEFAULT_FEE', 5000), // UGX
        ],

        /*
        |--------------------------------------------------------------------------
        | Payment Settings
        |--------------------------------------------------------------------------
        |
        | Payment method configurations
        |
        */
        'payments' => [
            'mobile_money' => [
                'enabled' => env('STORE_MOBILE_MONEY_ENABLED', true),
                'providers' => ['mtn', 'airtel'],
            ],
            'credits' => [
                'enabled' => env('STORE_CREDITS_ENABLED', true),
                'allow_partial' => env('STORE_CREDITS_ALLOW_PARTIAL', true),
            ],
            'bank_transfer' => [
                'enabled' => env('STORE_BANK_TRANSFER_ENABLED', false),
                'manual_verification' => true,
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Promotion Settings
        |--------------------------------------------------------------------------
        |
        | Artist-to-user promotion configurations
        |
        */
        'promotions' => [
            'enabled' => env('STORE_PROMOTIONS_ENABLED', true),
            'require_approval' => env('STORE_PROMOTIONS_REQUIRE_APPROVAL', true),
            'max_redemptions_per_user' => (int) env('STORE_MAX_REDEMPTIONS_PER_USER', 1),
            'verification_methods' => [
                'social_post' => true,     // Verify social media posts
                'radio_mention' => true,   // Verify radio/DJ mentions
                'event_attendance' => true, // Verify event check-ins
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Digital Products
        |--------------------------------------------------------------------------
        |
        | Settings for digital product downloads
        |
        */
        'digital_products' => [
            'enabled' => env('STORE_DIGITAL_PRODUCTS_ENABLED', true),
            'max_file_size' => (int) env('STORE_DIGITAL_MAX_FILE_SIZE', 52428800), // 50MB
            'download_limit' => (int) env('STORE_DIGITAL_DOWNLOAD_LIMIT', 5), // Downloads per purchase
            'link_expiry_days' => (int) env('STORE_DIGITAL_LINK_EXPIRY', 30),
        ],

        /*
        |--------------------------------------------------------------------------
        | Service Products
        |--------------------------------------------------------------------------
        |
        | Settings for service-based products (consultations, meet & greets)
        |
        */
        'services' => [
            'enabled' => env('STORE_SERVICES_ENABLED', true),
            'booking_advance_days' => (int) env('STORE_SERVICE_BOOKING_ADVANCE', 7),
            'cancellation_hours' => (int) env('STORE_SERVICE_CANCELLATION_HOURS', 24),
        ],
    ],
];
