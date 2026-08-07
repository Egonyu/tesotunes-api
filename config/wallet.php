<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wallet transaction PIN
    |--------------------------------------------------------------------------
    |
    | A short PIN that authorizes money movement (withdrawals, transfers, tips,
    | purchases). Because a 4-digit PIN has only 10,000 combinations, the
    | lockout below is a security requirement, not a nicety.
    |
    */

    'pin' => [
        // Master switch for the route gate. Ships OFF so the backend can deploy
        // ahead of the PIN UI: until the frontend can prompt for a PIN, blocking
        // withdrawals would lock every existing user out of their own money.
        // Flip to true only once the PIN modals are live.
        'enforce' => (bool) env('WALLET_PIN_ENFORCE', false),

        // Digits required. Keep in sync with the frontend PIN input.
        'length' => (int) env('WALLET_PIN_LENGTH', 4),

        // Wrong attempts before the PIN locks.
        'max_attempts' => (int) env('WALLET_PIN_MAX_ATTEMPTS', 5),

        // How long the PIN stays locked after too many wrong attempts.
        'lockout_minutes' => (int) env('WALLET_PIN_LOCKOUT_MINUTES', 15),

        // How long one successful verification authorizes further money
        // movement on that same session before the PIN is asked for again.
        'session_minutes' => (int) env('WALLET_PIN_SESSION_MINUTES', 5),

        // Trivially guessable PINs to reject at setup time.
        'blocklist' => [
            '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777',
            '8888', '9999', '1234', '4321', '0123', '3210', '1212', '2580',
        ],
    ],

];
