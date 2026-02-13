<?php

if (!function_exists('format_ugx')) {
    /**
     * Format amount as Uganda Shillings (UGX)
     * 
     * @param float|int $amount
     * @param bool $showSymbol Show "UGX" symbol
     * @return string
     */
    function format_ugx($amount, bool $showSymbol = true): string
    {
        $formatted = number_format($amount, 0, '.', ',');
        return $showSymbol ? "UGX {$formatted}" : $formatted;
    }
}

if (!function_exists('format_credits')) {
    /**
     * Format amount as platform credits
     * 
     * @param float|int $amount
     * @param bool $showLabel Show "Credits" label
     * @return string
     */
    function format_credits($amount, bool $showLabel = true): string
    {
        $formatted = number_format($amount, 0, '.', ',');
        return $showLabel ? "{$formatted} Credits" : $formatted;
    }
}

if (!function_exists('format_currency_with_credits')) {
    /**
     * Format amount showing both UGX and Credits options
     * 
     * @param float|int $ugxAmount
     * @param float|int|null $creditsAmount
     * @return string
     */
    function format_currency_with_credits($ugxAmount, float|int|null $creditsAmount = null): string
    {
        $formatted = format_ugx($ugxAmount);
        
        if ($creditsAmount !== null && $creditsAmount > 0) {
            $formatted .= ' <span class="text-gray-400">or</span> ' . format_credits($creditsAmount);
        }
        
        return $formatted;
    }
}

if (!function_exists('ugx_to_credits')) {
    /**
     * Convert UGX to Credits based on conversion rate
     * 
     * @param float|int $ugxAmount
     * @return int
     */
    function ugx_to_credits($ugxAmount): int
    {
        $conversionRate = config('store.currencies.credits.conversion_rate', 1);
        return (int) ($ugxAmount * $conversionRate);
    }
}

if (!function_exists('credits_to_ugx')) {
    /**
     * Convert Credits to UGX based on conversion rate
     * 
     * @param float|int $creditsAmount
     * @return int
     */
    function credits_to_ugx($creditsAmount): int
    {
        $conversionRate = config('store.currencies.credits.conversion_rate', 1);
        return (int) ($creditsAmount / $conversionRate);
    }
}

if (!function_exists('format_price_range')) {
    /**
     * Format a price range (e.g., for products with variants)
     * 
     * @param float|int $minPrice
     * @param float|int $maxPrice
     * @return string
     */
    function format_price_range($minPrice, $maxPrice): string
    {
        if ($minPrice === $maxPrice) {
            return format_ugx($minPrice);
        }
        
        return format_ugx($minPrice) . ' - ' . format_ugx($maxPrice);
    }
}
