<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily Listen-to-Earn Pool Size
    |--------------------------------------------------------------------------
    |
    | Total credits distributed each day to listeners proportional to their
    | qualified listening time (90%+ completion, no forward seeking).
    | Override via CREDITS_LISTEN_EARN_DAILY_POOL in .env.
    |
    */
    'listen_earn_daily_pool' => (int) env('CREDITS_LISTEN_EARN_DAILY_POOL', 1000),

    /*
    |--------------------------------------------------------------------------
    | Welcome Bonus
    |--------------------------------------------------------------------------
    |
    | Credits awarded to every new user on registration.
    |
    */
    'welcome_bonus' => (int) env('CREDITS_WELCOME_BONUS', 200),

    /*
    |--------------------------------------------------------------------------
    | First Listen Bonus
    |--------------------------------------------------------------------------
    |
    | One-time bonus awarded when a user completes their first qualified listen.
    |
    */
    'first_listen_bonus' => (int) env('CREDITS_FIRST_LISTEN_BONUS', 50),

];
