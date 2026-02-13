<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Firebase Cloud Messaging (FCM) and other Firebase services
    | You'll need to add your Firebase service account JSON file to your project
    |
    */

    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'sender_id' => env('FCM_SENDER_ID'),
        'project_id' => env('FCM_PROJECT_ID'),
    ],

    'service_account' => [
        'path' => env('FIREBASE_SERVICE_ACCOUNT_PATH', storage_path('app/firebase-service-account.json')),
    ],

    'project_id' => env('FIREBASE_PROJECT_ID'),

    'database_url' => env('FIREBASE_DATABASE_URL'),
];