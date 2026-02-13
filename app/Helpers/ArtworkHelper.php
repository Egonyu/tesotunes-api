<?php

namespace App\Helpers;

class ArtworkHelper
{
    /**
     * Uganda flag colors for gradients
     */
    private static $ugandaColors = [
        ['from' => 'rgb(0, 0, 0)', 'to' => 'rgb(252, 209, 22)'],      // Black to Yellow
        ['from' => 'rgb(206, 17, 38)', 'to' => 'rgb(0, 0, 0)'],       // Red to Black
        ['from' => 'rgb(252, 209, 22)', 'to' => 'rgb(206, 17, 38)'],  // Yellow to Red
        ['from' => 'rgb(0, 0, 0)', 'to' => 'rgb(206, 17, 38)'],       // Black to Red
        ['from' => 'rgb(206, 17, 38)', 'to' => 'rgb(252, 209, 22)'],  // Red to Yellow
        ['from' => 'rgb(252, 209, 22)', 'to' => 'rgb(0, 0, 0)'],      // Yellow to Black
    ];

    /**
     * Tailwind gradient classes for Uganda flag colors
     */
    private static $ugandaTailwindGradients = [
        'from-black via-yellow-400 to-red-600',
        'from-red-600 via-black to-yellow-400',
        'from-yellow-400 via-red-600 to-black',
        'from-black to-red-600',
        'from-red-600 to-yellow-400',
        'from-yellow-400 to-black',
        'from-red-600 via-yellow-400 to-red-600',
        'from-black via-red-600 to-yellow-400',
    ];

    /**
     * Generate a gradient style based on ID for consistency
     */
    public static function getGradientStyle($id, $direction = 'to bottom right')
    {
        $index = $id ? abs(crc32($id)) % count(self::$ugandaColors) : 0;
        $colors = self::$ugandaColors[$index];

        return sprintf(
            'background: linear-gradient(%s, %s, %s);',
            $direction,
            $colors['from'],
            $colors['to']
        );
    }

    /**
     * Get Tailwind gradient class based on ID
     */
    public static function getTailwindGradient($id)
    {
        $index = $id ? abs(crc32($id)) % count(self::$ugandaTailwindGradients) : 0;
        return self::$ugandaTailwindGradients[$index];
    }

    /**
     * Generate pixelated gradient pattern similar to Apple Music charts
     */
    public static function getPixelatedGradient($id, $rows = 6, $cols = 8)
    {
        $seed = $id ? abs(crc32($id)) : rand(1, 1000);
        $index = $seed % count(self::$ugandaColors);
        $colors = self::$ugandaColors[$index];

        // Parse RGB values
        preg_match_all('/\d+/', $colors['from'], $fromMatches);
        preg_match_all('/\d+/', $colors['to'], $toMatches);

        $fromRGB = array_map('intval', $fromMatches[0]);
        $toRGB = array_map('intval', $toMatches[0]);

        $pixels = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                // Calculate interpolation factor
                $factor = ($row * $cols + $col) / ($rows * $cols - 1);

                // Interpolate between colors
                $r = round($fromRGB[0] + ($toRGB[0] - $fromRGB[0]) * $factor);
                $g = round($fromRGB[1] + ($toRGB[1] - $fromRGB[1]) * $factor);
                $b = round($fromRGB[2] + ($toRGB[2] - $fromRGB[2]) * $factor);

                // Add some randomness for variety
                $r = max(0, min(255, $r + rand(-20, 20)));
                $g = max(0, min(255, $g + rand(-20, 20)));
                $b = max(0, min(255, $b + rand(-20, 20)));

                $pixels[] = [
                    'color' => "rgb($r, $g, $b)",
                    'row' => $row,
                    'col' => $col
                ];
            }
        }

        return $pixels;
    }

    /**
     * Get random Uganda-themed gradient
     */
    public static function getRandomGradient()
    {
        return self::$ugandaTailwindGradients[array_rand(self::$ugandaTailwindGradients)];
    }

    /**
     * Generate SVG gradient artwork
     */
    public static function generateSvgGradient($id, $width = 400, $height = 400)
    {
        $gradient = self::getTailwindGradient($id);
        $index = $id ? abs(crc32($id)) % count(self::$ugandaColors) : 0;
        $colors = self::$ugandaColors[$index];

        return sprintf(
            '<svg width="%d" height="%d" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <linearGradient id="grad%s" x1="0%%" y1="0%%" x2="100%%" y2="100%%">
                        <stop offset="0%%" style="stop-color:%s;stop-opacity:1" />
                        <stop offset="100%%" style="stop-color:%s;stop-opacity:1" />
                    </linearGradient>
                </defs>
                <rect width="100%%" height="100%%" fill="url(#grad%s)" />
            </svg>',
            $width,
            $height,
            $id,
            $colors['from'],
            $colors['to'],
            $id
        );
    }
}
