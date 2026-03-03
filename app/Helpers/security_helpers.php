<?php

if (! function_exists('sanitize_input')) {
    function sanitize_input(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('escape_like')) {
    /**
     * Escape special LIKE wildcard characters (% and _) in a search string.
     *
     * Prevents user-supplied input from being interpreted as SQL wildcards
     * in LIKE queries. Always use this when interpolating user input into
     * LIKE patterns: ->where('col', 'LIKE', '%' . escape_like($input) . '%')
     *
     * @see HIGH-2 in PLATFORM_STABILITY_AUDIT.md
     */
    function escape_like(string $value): string
    {
        return addcslashes($value, '%_');
    }
}

if (! function_exists('is_secure_request')) {
    function is_secure_request(): bool
    {
        return request()->secure();
    }
}
