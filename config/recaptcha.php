<?php

return [
    'enabled' => (bool) env('RECAPTCHA_ENABLED', true),
    'site_key' => env('RECAPTCHA_SITE_KEY', ''),
    'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
    'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),

    // reCAPTCHA Enterprise settings
    // Set RECAPTCHA_ENTERPRISE=true and supply RECAPTCHA_PROJECT_ID + RECAPTCHA_API_KEY
    // to use the Enterprise Assessment API instead of the standard siteverify endpoint.
    'enterprise' => (bool) env('RECAPTCHA_ENTERPRISE', false),
    'project_id' => env('RECAPTCHA_PROJECT_ID', ''),
    'api_key' => env('RECAPTCHA_API_KEY', ''),
];
