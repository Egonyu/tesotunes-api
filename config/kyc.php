<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KYC Policy Toggles
    |--------------------------------------------------------------------------
    |
    | These flags control how strict KYC enforcement is. Defaults are tuned
    | for the current state of the platform (SMS verification not yet built).
    | Flip them as infrastructure comes online.
    |
    */

    // When true, KycService requires users.phone_verified_at to be set
    // (i.e. a real SMS code was confirmed). When false, mere phone presence
    // is treated as sufficient — the bridge state while SMS is unfinished.
    'require_phone_verification' => env('KYC_REQUIRE_PHONE_VERIFICATION', false),

    // Days a verification stays valid before re-KYC is required.
    'verification_ttl_days' => env('KYC_VERIFICATION_TTL_DAYS', 365),

    // Max upload size (in kilobytes) for a single KYC document.
    'max_document_size_kb' => env('KYC_MAX_DOCUMENT_SIZE_KB', 5120),

    // Mime types accepted for KYC document uploads.
    'accepted_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],
];
