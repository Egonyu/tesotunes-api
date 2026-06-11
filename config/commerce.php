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
];
