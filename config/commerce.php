<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settlement clearance holds
    |--------------------------------------------------------------------------
    |
    | Days a settlement stays PENDING before the scheduled clearance command
    | promotes it to CLEARED (the dispute/refund window). A vertical may be
    | overridden per record by passing an explicit hold_until to
    | SettlementService::record() — e.g. event ticket sales hold until the
    | event has ended.
    |
    */

    'settlement_hold_days' => [
        'default' => env('COMMERCE_SETTLEMENT_HOLD_DAYS', 3),
        'store' => env('COMMERCE_STORE_HOLD_DAYS', 3),
        'events' => env('COMMERCE_EVENTS_HOLD_DAYS', 1),
        'promotions' => env('COMMERCE_PROMOTIONS_HOLD_DAYS', 2),
        'music' => env('COMMERCE_MUSIC_HOLD_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet payout verticals
    |--------------------------------------------------------------------------
    |
    | Verticals whose cleared settlements are paid into the beneficiary's
    | wallet by commerce:clear-due-settlements (SettlementPayoutService).
    | Music and contributions are paid by their own flows and only mirror
    | into the ledger, so they must never be listed here.
    |
    */

    'wallet_payout_verticals' => ['store', 'events', 'promotions'],
];
