<?php

// This is a symlink/wrapper to the module config
// Allows config('store.enabled') to work throughout the app

return require __DIR__ . '/../app/Modules/Store/Config/store.php';
