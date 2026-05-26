<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://tesotunes.test',
        'http://tesotunes-next-web.test',
        'http://tesotunes-api.test',
        'https://api.tesotunes.com',
        'https://engine.tesotunes.com',
        'https://tesotunes.com',
        'https://www.tesotunes.com',
        env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'allowed_origins_patterns' => [
        // Production domain pattern
        '/^https:\/\/.*\.tesotunes\.com$/',
        // Local Herd dev domains (*.test are non-routable outside localhost)
        '/^https?:\/\/[a-zA-Z0-9-]+\.test$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization'],

    'max_age' => 600, // 10 minutes

    'supports_credentials' => true,

];
