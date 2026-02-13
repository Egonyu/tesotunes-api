<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

/**
 * Cache Helper - Provides tagging-safe cache operations
 * 
 * This helper provides a unified interface for cache operations that works
 * with both tagging-capable stores (Redis, Memcached) and non-tagging stores
 * (file, database, array).
 */
class CacheHelper
{
    /**
     * Check if the current cache store supports tagging
     */
    public static function supportsTagging(): bool
    {
        $driver = config('cache.default');
        $store = Cache::getStore();
        
        // Redis and Memcached support tagging
        return in_array($driver, ['redis', 'memcached']) || 
               method_exists($store, 'tags');
    }

    /**
     * Remember a value in cache with optional tags
     * 
     * @param array|string $tags Cache tags (ignored if tagging not supported)
     * @param string $key Cache key
     * @param \DateTimeInterface|\DateInterval|int|null $ttl Time to live
     * @param \Closure $callback Callback to get the value
     * @return mixed
     */
    public static function remember($tags, string $key, $ttl, \Closure $callback)
    {
        if (self::supportsTagging()) {
            $tags = is_array($tags) ? $tags : [$tags];
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        }

        // For non-tagging stores, prefix key with first tag
        $prefix = is_array($tags) ? $tags[0] : $tags;
        $prefixedKey = $prefix . ':' . $key;
        
        return Cache::remember($prefixedKey, $ttl, $callback);
    }

    /**
     * Get a value from cache with optional tags
     * 
     * @param array|string $tags Cache tags (ignored if tagging not supported)
     * @param string $key Cache key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function get($tags, string $key, mixed $default = null)
    {
        if (self::supportsTagging()) {
            $tags = is_array($tags) ? $tags : [$tags];
            return Cache::tags($tags)->get($key, $default);
        }

        // For non-tagging stores, prefix key with first tag
        $prefix = is_array($tags) ? $tags[0] : $tags;
        $prefixedKey = $prefix . ':' . $key;
        
        return Cache::get($prefixedKey, $default);
    }

    /**
     * Put a value in cache with optional tags
     * 
     * @param array|string $tags Cache tags (ignored if tagging not supported)
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param \DateTimeInterface|\DateInterval|int|null $ttl Time to live
     * @return bool
     */
    public static function put($tags, string $key, $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
    {
        if (self::supportsTagging()) {
            $tags = is_array($tags) ? $tags : [$tags];
            return Cache::tags($tags)->put($key, $value, $ttl);
        }

        // For non-tagging stores, prefix key with first tag
        $prefix = is_array($tags) ? $tags[0] : $tags;
        $prefixedKey = $prefix . ':' . $key;
        
        return Cache::put($prefixedKey, $value, $ttl);
    }

    /**
     * Flush cache by tags
     * 
     * @param array|string $tags Cache tags
     * @return bool
     */
    public static function flush($tags = null): bool
    {
        if ($tags === null) {
            // Flush all cache
            return Cache::flush();
        }

        if (self::supportsTagging()) {
            $tags = is_array($tags) ? $tags : [$tags];
            return Cache::tags($tags)->flush();
        }

        // For non-tagging stores, we can't selectively flush by tag
        // So we flush the entire cache (this is a limitation)
        // In production, you should use Redis/Memcached for better performance
        return Cache::flush();
    }

    /**
     * Forget a cache key with optional tags
     * 
     * @param array|string|null $tags Cache tags (ignored if tagging not supported)
     * @param string|null $key Cache key
     * @return bool
     */
    public static function forget($tags = null, ?string $key = null): bool
    {
        // If no key provided, flush by tags
        if ($key === null) {
            return self::flush($tags);
        }

        if ($tags && self::supportsTagging()) {
            $tags = is_array($tags) ? $tags : [$tags];
            return Cache::tags($tags)->forget($key);
        }

        if ($tags) {
            // For non-tagging stores, prefix key with first tag
            $prefix = is_array($tags) ? $tags[0] : $tags;
            $prefixedKey = $prefix . ':' . $key;
            return Cache::forget($prefixedKey);
        }

        return Cache::forget($key);
    }
}
