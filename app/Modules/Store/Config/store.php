<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Store Module Configuration
    |--------------------------------------------------------------------------
    |
    | Master toggle for the entire store/e-commerce module.
    | When disabled, no routes, database queries, or UI elements are loaded.
    |
    */

    'enabled' => env('STORE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Currency Support - CRITICAL FEATURE
    |--------------------------------------------------------------------------
    | PRIMARY: Real money (UGX via Mobile Money)
    | SECONDARY: Platform credits (encourage platform engagement)
    | HYBRID: Allow mix of both in single transaction
    */

    'currencies' => [
        'primary' => [
            'code' => 'UGX',
            'symbol' => 'UGX',
            'name' => 'Uganda Shillings',
            'decimals' => 0,
        ],
        'credits' => [
            'enabled' => true,
            'allow_purchase_with_credits' => true,
            'allow_hybrid_payment' => true, // Credits + Mobile Money
            'conversion_rate' => 1, // 1 credit = 1 UGX
            'max_credits_per_order_percentage' => 50, // Max 50% of order value
            'bonus_for_credit_use' => 5, // 5% bonus when using credits
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Fees (Percentage)
    |--------------------------------------------------------------------------
    */

    'fees' => [
        'free_tier' => (float) env('STORE_FREE_TIER_FEE', 7.0),
        'premium_tier' => (float) env('STORE_PREMIUM_TIER_FEE', 5.0),
        'business_tier' => (float) env('STORE_BUSINESS_TIER_FEE', 3.0),
        'minimum_fee' => (int) env('STORE_MINIMUM_FEE', 1000), // UGX
        
        // Promotion-specific fees (higher than physical products)
        'promotion_free_tier' => (float) env('STORE_PROMOTION_FREE_TIER_FEE', 10.0),
        'promotion_premium_tier' => (float) env('STORE_PROMOTION_PREMIUM_TIER_FEE', 7.0),
        'promotion_business_tier' => (float) env('STORE_PROMOTION_BUSINESS_TIER_FEE', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    */

    'subscriptions' => [
        'premium' => [
            'name' => 'Premium Store',
            'price_ugx' => 20000,
            'price_credits' => 18000, // 10% discount for paying with credits
            'features' => [
                'unlimited_products',
                'reduced_fees',
                'advanced_analytics',
                'priority_support',
            ],
        ],
        'business' => [
            'name' => 'Business Store',
            'price_ugx' => 50000,
            'price_credits' => 45000,
            'features' => [
                'all_premium_features',
                'lowest_fees',
                'api_access',
                'dedicated_manager',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Settings
    |--------------------------------------------------------------------------
    */

    'stores' => [
        'allow_user_stores' => env('STORE_ALLOW_USER_STORES', true),
        'require_verification' => true,
        'auto_approve_products' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'free_tier_products' => 10,
        'premium_tier_products' => -1, // Unlimited
        'max_images_per_product' => 8,
        'max_image_size' => 10485760, // 10MB
        'max_digital_file_size' => 524288000, // 500MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Configuration - Platform Handles Logistics
    |--------------------------------------------------------------------------
    */

    'payments' => [
        'escrow_hold_days' => 7,
        'auto_release_days' => 7,
        'payout_day' => 1,
        'minimum_payout_ugx' => 50000,
        'minimum_payout_credits' => 50000,
        'methods' => [
            'mobile_money' => true,
            'credits' => true,
            'hybrid' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fulfillment - Seller Responsibility
    |--------------------------------------------------------------------------
    */

    'fulfillment' => [
        'self_fulfillment_default' => true,
        'managed_by_platform' => false, // Platform only provides marketplace
        'seller_handles_logistics' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping Configuration
    |--------------------------------------------------------------------------
    */

    'free_shipping_threshold' => env('STORE_FREE_SHIPPING_THRESHOLD', 50000), // UGX 50,000

    /*
    |--------------------------------------------------------------------------
    | Product Types
    |--------------------------------------------------------------------------
    */

    'product_types' => [
        'physical' => [
            'name' => 'Physical Product',
            'requires_shipping' => true,
            'icon' => 'box',
        ],
        'digital' => [
            'name' => 'Digital Product',
            'requires_shipping' => false,
            'icon' => 'download',
        ],
        'service' => [
            'name' => 'Service',
            'requires_shipping' => false,
            'icon' => 'briefcase',
        ],
        'experience' => [
            'name' => 'Experience',
            'requires_shipping' => false,
            'icon' => 'calendar',
        ],
        'promotion' => [
            'name' => 'Promotional Service',
            'requires_shipping' => false,
            'icon' => 'megaphone',
            'supports_both_currencies' => true, // Can use UGX or Credits
            'higher_commission' => true, // Uses promotion_*_tier fees
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */

    'features' => [
        'cart_enabled' => true,
        'reviews_enabled' => true,
        'promotions_enabled' => true,
        'credit_payments_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'storage' => [
        'disk' => env('STORE_STORAGE_DISK', 'public'),
        'paths' => [
            'store_logos' => 'stores/logos',
            'store_banners' => 'stores/banners',
            'product_images' => 'stores/products/images',
            'digital_products' => 'stores/products/digital',
        ],
    ],
];
