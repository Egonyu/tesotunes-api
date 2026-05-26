<?php

return [
    /*
     | Master kill-switch for the ad serving system.
     | Set ADS_ENABLED=false in .env to disable all ad delivery
     | without touching frontend code or database records.
     */
    'enabled' => env('ADS_ENABLED', true),
];
