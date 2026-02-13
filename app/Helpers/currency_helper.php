<?php

if (!function_exists('format_ugx')) {
    /**
     * Format amount as Uganda Shillings
     * Example: 50000 -> "UGX 50,000"
     *
     * @param int|float|null $amount
     * @return string
     */
    function format_ugx(int|float|null $amount): string
    {
        if ($amount === null) {
            return 'UGX 0';
        }
        
        return 'UGX ' . number_format($amount, 0, '.', ',');
    }
}

if (!function_exists('format_credits')) {
    /**
     * Format platform credits
     * Example: 5000 -> "5,000 Credits"
     *
     * @param int|float|null $amount
     * @return string
     */
    function format_credits(int|float|null $amount): string
    {
        if ($amount === null) {
            return '0 Credits';
        }
        
        return number_format($amount, 0, '.', ',') . ' Credits';
    }
}

if (!function_exists('parse_ugx')) {
    /**
     * Parse UGX string back to integer
     * Example: "UGX 50,000" -> 50000
     *
     * @param string $ugx
     * @return int
     */
    function parse_ugx(string $ugx): int
    {
        return (int) preg_replace('/[^0-9]/', '', $ugx);
    }
}

if (!function_exists('format_currency')) {
    /**
     * Format amount with currency symbol (UGX or Credits)
     * Automatically detects which currency to use
     *
     * @param int|float|null $ugxAmount
     * @param int|float|null $creditsAmount
     * @return string
     */
    function format_currency(int|float|null $ugxAmount = null, int|float|null $creditsAmount = null): string
    {
        $parts = [];
        
        if ($ugxAmount !== null && $ugxAmount > 0) {
            $parts[] = format_ugx($ugxAmount);
        }
        
        if ($creditsAmount !== null && $creditsAmount > 0) {
            $parts[] = format_credits($creditsAmount);
        }
        
        if (empty($parts)) {
            return format_ugx(0);
        }
        
        return implode(' or ', $parts);
    }
}
