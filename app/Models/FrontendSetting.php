<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FrontendSetting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'frontend_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'category',
        'key',
        'value',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'frontend_setting_';
    const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value by key and type
     *
     * @param string $key
     * @param string $type
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, string $type = 'general', mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $type . '_' . $key;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $type, $default) {
            $setting = self::where('key', $key)
                ->where('type', $type)
                ->first();

            if (!$setting) {
                // Fall back to general type
                $setting = self::where('key', $key)
                    ->where('type', 'general')
                    ->first();
            }

            return $setting?->value ?? $default;
        });
    }

    /**
     * Set a setting value
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @param string|null $dataType
     * @param string $category
     * @return static
     */
    public static function set(string $key, mixed $value, string $type = 'general', ?string $dataType = null, string $category = 'general'): static
    {
        $setting = self::updateOrCreate(
            ['key' => $key, 'type' => $type],
            [
                'value' => $value,
                'category' => $category,
            ]
        );

        // Clear cache for this setting
        Cache::forget(self::CACHE_PREFIX . $type . '_' . $key);

        return $setting;
    }

    /**
     * Clear all frontend settings cache
     *
     * @return void
     */
    public static function clearCache(): void
    {
        // Clear all cached settings
        Cache::flush();
    }

    /**
     * Get all settings for a specific type
     *
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByType(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('type', $type)->get();
    }

    /**
     * Get all settings for a specific category
     *
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getByCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('category', $category)->get();
    }
}
