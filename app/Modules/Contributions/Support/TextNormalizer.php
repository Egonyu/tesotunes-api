<?php

namespace App\Modules\Contributions\Support;

use Illuminate\Support\Str;

/**
 * Lightweight normalization for comparing free-text answers — used both to
 * score gold submissions against their known answer and to detect translator
 * convergence (independent answers that agree). This is a comparison key, not
 * the house-orthography normalizer (that lands with the style guide later).
 */
class TextNormalizer
{
    /**
     * Lowercase, strip punctuation/diacritics noise, collapse whitespace.
     */
    public static function key(string $text): string
    {
        $text = Str::lower(trim($text));
        // Drop common punctuation so "Eong ajokis." == "eong ajokis".
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Do two answers match after normalization?
     */
    public static function matches(string $a, string $b): bool
    {
        $ka = self::key($a);

        return $ka !== '' && $ka === self::key($b);
    }
}
