<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Artist Payout Settings
    |--------------------------------------------------------------------------
    |
    | All monetary values are in UGX (Ugandan Shilling).
    | Fee percentages are expressed as plain numbers (e.g. 1.5 = 1.5%).
    |
    */

    'payout' => [
        'min_amount' => (int) env('PAYOUT_MIN_AMOUNT', 50000),    // UGX 50,000  (~$13)
        'max_single' => (int) env('PAYOUT_MAX_SINGLE', 5000000),   // UGX 5,000,000 (~$1,333)
        'max_daily' => (int) env('PAYOUT_MAX_DAILY', 10000000),   // UGX 10,000,000 (~$2,666)

        'fees' => [
            'mobile_money' => (float) env('PAYOUT_FEE_MOBILE_MONEY', 1.5), // %
            'bank_transfer' => (float) env('PAYOUT_FEE_BANK_TRANSFER', 0.5), // %
            'paypal' => (float) env('PAYOUT_FEE_PAYPAL', 2.0), // %
        ],

        'auto_process_approved' => (bool) env('PAYOUT_AUTO_PROCESS', false),
    ],

];
