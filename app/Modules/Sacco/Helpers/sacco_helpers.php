<?php

if (!function_exists('sacco_enabled')) {
    /**
     * Check if SACCO module is enabled
     */
    function sacco_enabled(): bool
    {
        return config('sacco.enabled', false);
    }
}

if (!function_exists('sacco_format_currency')) {
    /**
     * Format amount as UGX currency
     */
    function sacco_format_currency(float $amount): string
    {
        return 'UGX ' . number_format($amount, 2);
    }
}

if (!function_exists('sacco_config')) {
    /**
     * Get SACCO configuration value
     */
    function sacco_config(string $key, $default = null)
    {
        return config("sacco.{$key}", $default);
    }
}

if (!function_exists('sacco_generate_reference')) {
    /**
     * Generate unique transaction reference
     */
    function sacco_generate_reference(string $prefix = 'SACT'): string
    {
        return $prefix . date('YmdHis') . rand(1000, 9999);
    }
}

if (!function_exists('sacco_generate_account_number')) {
    /**
     * Generate account number
     */
    function sacco_generate_account_number(string $type = 'SAV'): string
    {
        $prefix = config('sacco.membership.member_number_prefix', 'SACCO');
        return $prefix . '-' . $type . '-' . date('Y') . '-' . rand(100000, 999999);
    }
}

if (!function_exists('sacco_generate_loan_number')) {
    /**
     * Generate loan number
     */
    function sacco_generate_loan_number(): string
    {
        return 'LOAN-' . date('Y') . '-' . rand(10000, 99999);
    }
}

if (!function_exists('sacco_calculate_interest')) {
    /**
     * Calculate simple interest
     */
    function sacco_calculate_interest(float $principal, float $rate, int $days): float
    {
        return ($principal * $rate * $days) / (100 * 365);
    }
}

if (!function_calls('sacco_format_date')) {
    /**
     * Format date for SACCO display
     */
    function sacco_format_date($date, string $format = 'd M Y'): string
    {
        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }
        
        return $date?->format($format) ?? '';
    }
}

if (!function_exists('sacco_member_url')) {
    /**
     * Generate SACCO member dashboard URL
     */
    function sacco_member_url(string $path = ''): string
    {
        return url('/sacco/member/' . ltrim($path, '/'));
    }
}

if (!function_exists('sacco_admin_url')) {
    /**
     * Generate SACCO admin URL
     */
    function sacco_admin_url(string $path = ''): string
    {
        return url('/sacco/admin/' . ltrim($path, '/'));
    }
}
