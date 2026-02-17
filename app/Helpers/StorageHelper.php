<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class StorageHelper
{
    /**
     * Generate a full URL for a stored file path.
     *
     * @param string|null $path The relative storage path
     * @return string|null The full URL or null if path is empty
     */
    public static function url(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        // If it's already a full URL, return as-is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Try to generate URL from the default disk
        try {
            // Storage::url() returns relative paths, so we need to prepend APP_URL
            $storageUrl = Storage::url($path);
            if (str_starts_with($storageUrl, '/')) {
                return config('app.url') . $storageUrl;
            }
            return $storageUrl;
        } catch (\Exception $e) {
            return url('storage/' . $path);
        }
    }

    /**
     * Generate artwork URL with a default fallback.
     *
     * @param string|null $artwork The artwork path
     * @param string|null $default Default image path
     * @return string|null
     */
    public static function artworkUrl(?string $artwork, ?string $default = null): ?string
    {
        if (!empty($artwork)) {
            return static::url($artwork);
        }

        if ($default) {
            return url($default);
        }

        return null;
    }

    /**
     * Generate avatar URL with a default UI avatar fallback.
     *
     * @param string|null $avatar The avatar path
     * @param string $name The name to use for generating default avatar
     * @return string
     */
    public static function avatarUrl(?string $avatar, string $name = 'User'): string
    {
        if (!empty($avatar)) {
            return static::url($avatar) ?? '';
        }

        // Generate a default avatar URL using UI Avatars
        $encodedName = urlencode($name);
        return "https://ui-avatars.com/api/?name={$encodedName}&background=random&size=200";
    }
}
