<?php

if (! function_exists('is_production')) {
    function is_production(): bool
    {
        return app()->environment('production');
    }
}

if (! function_exists('is_local')) {
    function is_local(): bool
    {
        return app()->environment('local');
    }
}

if (! function_exists('is_staging')) {
    function is_staging(): bool
    {
        return app()->environment('staging');
    }
}
