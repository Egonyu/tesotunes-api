<?php

if (! function_exists('sanitize_input')) {
    function sanitize_input(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('is_secure_request')) {
    function is_secure_request(): bool
    {
        return request()->secure();
    }
}
