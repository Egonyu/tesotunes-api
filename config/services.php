<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // SMS Service Configuration
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'mock'), // mock, africastalking, twilio

        'africastalking' => [
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'username' => env('AFRICASTALKING_USERNAME'),
            'sender_id' => env('AFRICASTALKING_SENDER_ID', 'MUSICAPP'),
        ],

        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from_number' => env('TWILIO_FROM_NUMBER'),
        ],
    ],

    // Uganda Music Rights Organization API
    'umro' => [
        'api_url' => env('UMRO_API_URL', 'https://api.umro.ug'),
        'api_key' => env('UMRO_API_KEY'),
        'enabled' => env('UMRO_ENABLED', false),
    ],

    // Google Analytics
    'google_analytics' => [
        'tracking_id' => env('GOOGLE_ANALYTICS_ID'),
        'enabled' => env('GOOGLE_ANALYTICS_ENABLED', false),
    ],

    // Google AdSense
    'adsense' => [
        'client_id' => env('GOOGLE_ADSENSE_CLIENT_ID'),
        'enabled' => env('GOOGLE_ADSENSE_ENABLED', false),
    ],

    // DigitalOcean Spaces (for file storage)
    'digitalocean' => [
        'key' => env('DO_SPACES_KEY'),
        'secret' => env('DO_SPACES_SECRET'),
        'endpoint' => env('DO_SPACES_ENDPOINT', 'https://nyc3.digitaloceanspaces.com'),
        'region' => env('DO_SPACES_REGION', 'nyc3'),
        'bucket' => env('DO_SPACES_BUCKET'),
        'cdn_endpoint' => env('DO_SPACES_CDN_ENDPOINT'),
    ],


    // OAuth Providers (Social Authentication)
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', env('APP_URL') . '/auth/facebook/callback'),
    ],

    // Wazuh SIEM Integration
    'wazuh' => [
        'enabled' => env('WAZUH_ENABLED', false),
        'api_url' => env('WAZUH_API_URL', 'https://localhost:55000'),
        'api_user' => env('WAZUH_API_USER', 'wazuh-wui'),
        'api_password' => env('WAZUH_API_PASSWORD'),
        'indexer_url' => env('WAZUH_INDEXER_URL', 'https://localhost:9200'),
        'dashboard_url' => env('WAZUH_DASHBOARD_URL', 'https://localhost:5601'),
    ],
];

