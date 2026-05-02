<?php

return [
    'enabled' => (bool) env('RECAPTCHA_ENABLED', true),
    'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
    'site_key' => env('RECAPTCHA_SITE_KEY', ''),
    'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
];
