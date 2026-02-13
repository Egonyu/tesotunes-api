<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    // Type constants
    public const TYPE_STRING = 'string';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_NUMBER = 'integer';  // Alias for TYPE_INTEGER
    public const TYPE_JSON = 'json';
    public const TYPE_ARRAY = 'array';

    // Group constants
    public const GROUP_GENERAL = 'general';
    public const GROUP_USERS = 'users';
    public const GROUP_CREDITS = 'credits';
    public const GROUP_PAYMENTS = 'payments';
    public const GROUP_PAYMENT = 'payments';  // Alias for GROUP_PAYMENTS
    public const GROUP_NOTIFICATIONS = 'notifications';
    public const GROUP_SECURITY = 'security';
    public const GROUP_MOBILE = 'mobile';
    public const GROUP_VERIFICATION = 'verification';
    public const GROUP_AWARDS = 'awards';
    public const GROUP_EVENTS = 'events';
    public const GROUP_ARTISTS = 'artists';
    public const GROUP_STORAGE = 'storage';
    public const GROUP_ANALYTICS = 'analytics';
    public const GROUP_ADS = 'ads';

    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'is_public',
        'description',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    /**
     * Get a setting value by key
     */
    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }

        // Cast value based on type
        switch ($setting->type) {
            case self::TYPE_BOOLEAN:
                return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
            case self::TYPE_INTEGER:
                return (int) $setting->value;
            case self::TYPE_JSON:
            case self::TYPE_ARRAY:
                return json_decode($setting->value, true) ?? $default;
            default:
                return $setting->value;
        }
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, $value, string $type = 'string', string $group = 'general'): self
    {
        // Convert value to string for storage
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = json_encode($value);
            $type = self::TYPE_JSON;
        }

        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => (string) $value,
                'type' => $type,
                'group' => $group,
            ]
        );
    }

    /**
     * Get all settings for a specific group
     */
    public static function getGroup(string $group): array
    {
        $settings = static::where('group', $group)->get();
        $result = [];
        
        foreach ($settings as $setting) {
            $result[$setting->key] = self::get($setting->key);
        }
        
        return $result;
    }

    /**
     * Check if a setting exists
     */
    public static function has(string $key): bool
    {
        return static::where('key', $key)->exists();
    }

    /**
     * Remove a setting
     */
    public static function remove(string $key): bool
    {
        return static::where('key', $key)->delete() > 0;
    }

    /**
     * Check if mobile verification is enabled
     */
    public static function isMobileVerificationEnabled(): bool
    {
        return static::get('mobile_verification_enabled', false);
    }

    /**
     * Check if email verification is enabled
     */
    public static function isEmailVerificationEnabled(): bool
    {
        return static::get('email_verification_enabled', true);
    }

    /**
     * Check if artist verification is required
     */
    public static function isArtistVerificationRequired(): bool
    {
        return static::get('artist_verification_required', true);
    }
}
