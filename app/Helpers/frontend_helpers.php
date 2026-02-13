<?php

if (!function_exists('frontend_setting')) {
    /**
     * Get a frontend setting value
     *
     * @param string $key
     * @param mixed $default
     * @param string|null $type Auto-detect mobile/desktop or force a type
     * @return mixed
     */
    function frontend_setting($key, mixed $default = null, ?string $type = null)
    {
        if ($type === null) {
            // Auto-detect device type
            $type = is_mobile_device() ? 'mobile' : 'desktop';
        }

        return \App\Models\FrontendSetting::get($key, $type, $default);
    }
}

if (!function_exists('is_mobile_device')) {
    /**
     * Detect if the current request is from a mobile device
     *
     * @return bool
     */
    function is_mobile_device()
    {
        if (request()->has('mobile_preview')) {
            return true;
        }

        $userAgent = request()->header('User-Agent', '');
        
        // Common mobile device patterns
        $mobilePatterns = [
            '/android/i',
            '/webos/i',
            '/iphone/i',
            '/ipad/i',
            '/ipod/i',
            '/blackberry/i',
            '/windows phone/i',
            '/mobile/i'
        ];

        foreach ($mobilePatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('section_enabled')) {
    /**
     * Check if a frontend section is enabled
     *
     * @param string $section
     * @param string|null $type
     * @return bool
     */
    function section_enabled($section, $type = null)
    {
        return (bool) frontend_setting("sections.show_{$section}", true, $type);
    }
}

if (!function_exists('theme_color')) {
    /**
     * Get a theme color
     *
     * @param string $color primary, background, text
     * @param string|null $type
     * @return string
     */
    function theme_color($color, $type = null)
    {
        $defaults = [
            'primary' => '#1DB954',
            'background' => '#121212',
            'text' => '#FFFFFF'
        ];

        return frontend_setting("theme.{$color}_color", $defaults[$color] ?? '#000000', $type);
    }
}
