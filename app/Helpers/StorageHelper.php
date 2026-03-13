<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StorageHelper
{
    /**
     * Resolve the configured media disk, falling back safely for local/private disks.
     */
    public static function resolvedMediaDisk(): string
    {
        $disk = config('filesystems.media_disk', env('MEDIA_DISK', config('filesystems.default', 'public')));

        return in_array($disk, ['local', 'private'], true) ? 'public' : $disk;
    }

    /**
     * Get the configured media disk name.
     *
     * In development: 'public' (local storage)
     * In production:  'digitalocean' (DO Spaces CDN)
     */
    public static function mediaDisk(): string
    {
        return static::resolvedMediaDisk();
    }

    /**
     * Store an uploaded file on the configured media disk.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  string  $directory  Subdirectory (e.g. 'artists/avatars', 'events/covers')
     * @param  string|null  $filename  Custom filename (auto-generated if null)
     * @return string The relative path suitable for saving in DB and passing to url()
     */
    public static function store(UploadedFile $file, string $directory, ?string $filename = null): string
    {
        $disk = static::resolvedMediaDisk();
        if ($filename === null) {
            $original = preg_replace('/[^a-zA-Z0-9._-]/', '', $file->getClientOriginalName());
            $ext = $file->getClientOriginalExtension();
            $base = pathinfo($original, PATHINFO_FILENAME);
            $base = $base !== '' ? $base : 'upload';
            $suffix = Str::lower(Str::random(12));
            $filename = $ext !== ''
                ? $base.'_'.$suffix.'.'.$ext
                : $base.'_'.$suffix;
        }

        if ($disk === 'public') {
            // Local storage: use move() to avoid Windows getRealPath() bug
            $targetDir = storage_path('app/public/'.$directory);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $file->move($targetDir, $filename);

            return $directory.'/'.$filename;
        }

        // Cloud storage: use Storage facade (works for DO Spaces, S3, etc.)
        $path = Storage::disk($disk)->putFileAs($directory, $file, $filename, 'public');

        return $path;
    }

    /**
     * Delete a file from the configured media disk.
     *
     * @param  string|null  $path  Relative path of the file to delete
     */
    public static function delete(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        // Skip external URLs
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $disk = static::resolvedMediaDisk();

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Exception $e) {
            // Silently ignore delete failures
        }
    }

    /**
     * Generate a pre-signed streaming URL for an audio file.
     * Picks the best available audio path (320 → 128 → original), generates a
     * short-lived signed URL for cloud storage, or a regular URL for local disks.
     *
     * This is the Spotify-style flow:
     *   1. Client requests stream_url from the API (authenticated)
     *   2. API generates a 15-min pre-signed CDN URL and returns it
     *   3. Client streams directly from CDN — no Laravel proxy overhead
     *
     * @param  string|null  $path320  Path to 320kbps file
     * @param  string|null  $path128  Path to 128kbps file
     * @param  string|null  $pathOriginal  Path to original file
     * @param  int  $minutes  URL validity in minutes
     */
    public static function streamingUrl(
        ?string $path320,
        ?string $path128,
        ?string $pathOriginal,
        int $minutes = 15
    ): ?string {
        $path = $path320 ?? $path128 ?? $pathOriginal;

        return static::temporaryUrl($path, $minutes);
    }

    /**
     * Generate a temporary signed URL for a stored file (cloud disks only).
     * Falls back to regular URL for local disks.
     *
     * @param  string|null  $path  The relative storage path
     * @param  int  $minutes  Expiry in minutes (default 15)
     */
    public static function temporaryUrl(?string $path, int $minutes = 15): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $disk = static::resolvedMediaDisk();

        if ($disk !== 'public') {
            try {
                return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
            } catch (\Exception $e) {
                // Disk doesn't support temporary URLs — fall back to regular URL
            }
        }

        return static::url($path);
    }

    /**
     * Generate a full URL for a stored file path.
     *
     * @param  string|null  $path  The relative storage path
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

        // Try cloud disk first if configured
        $disk = static::resolvedMediaDisk();
        if ($disk !== 'public') {
            try {
                return Storage::disk($disk)->url($path);
            } catch (\Exception $e) {
                // Fall through to local resolution
            }
        }

        // Local storage: generate URL from default public disk
        try {
            $storageUrl = Storage::url($path);
            if (str_starts_with($storageUrl, '/')) {
                return config('app.url').$storageUrl;
            }

            return $storageUrl;
        } catch (\Exception $e) {
            return url('storage/'.$path);
        }
    }

    /**
     * Generate artwork URL with a default fallback.
     *
     * @param  string|null  $artwork  The artwork path
     * @param  string|null  $default  Default image path
     */
    public static function artworkUrl(?string $artwork, ?string $default = null): ?string
    {
        if (! empty($artwork)) {
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
     * @param  string|null  $avatar  The avatar path
     * @param  string  $name  The name to use for generating default avatar
     */
    public static function avatarUrl(?string $avatar, string $name = 'User'): string
    {
        if (! empty($avatar)) {
            return static::url($avatar) ?? '';
        }

        // Generate a default avatar URL using UI Avatars
        $encodedName = urlencode($name);

        return "https://ui-avatars.com/api/?name={$encodedName}&background=random&size=200";
    }
}
