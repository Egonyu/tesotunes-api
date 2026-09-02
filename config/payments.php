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

    /*
    |--------------------------------------------------------------------------
    | Wallet Cash-Out Settings
    |--------------------------------------------------------------------------
    |
    | Distinct from artist payouts above: this is a listener cashing out their
    | own wallet, so the floor is far lower. It is not zero, though — ZengaPay's
    | per-transaction charge does not scale down. A measured 1,000 UGX
    | collection cost roughly 220 UGX in fees (~22%), so a 1,000 floor meant a
    | fifth of the money evaporated in charges. 5,000 puts that nearer 4%.
    |
    */

    'wallet_withdrawal' => [
        /*
         * The absolute floor, and the subscriber floor. Nobody may cash out
         * below this: a measured 1,000 UGX movement lost roughly 220 to
         * ZengaPay charges, so under 5,000 the user is mostly paying fees to
         * move their own money.
         */
        'min_amount' => (int) env('WALLET_WITHDRAWAL_MIN', 5000),

        /*
         * The floor for accounts without an active subscription. Subscribers
         * cash out in smaller pieces because their subscription offsets the
         * per-transaction charge; everyone else waits until the transfer is
         * large enough to be worth its fee.
         */
        'min_amount_free' => (int) env('WALLET_WITHDRAWAL_MIN_FREE', 25000),

        'max_single' => (int) env('WALLET_WITHDRAWAL_MAX_SINGLE', 5000000),
    ],

];
