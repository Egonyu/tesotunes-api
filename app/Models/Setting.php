<?php

namespace App\Models;

use App\Settings\SettingActor;
use App\Settings\SettingRegistry;
use App\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    // Type constants
    public const TYPE_STRING = 'string';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_NUMBER = 'integer';  // Alias for TYPE_INTEGER

    public const TYPE_FLOAT = 'float';

    public const TYPE_JSON = 'json';

    public const TYPE_ARRAY = 'array';

    // Group constants
    public const GROUP_GENERAL = 'general';

    public const GROUP_EMAIL = 'email';

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
        'is_secret',
        'description',
        'last_updated_by',
        'version',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_secret' => 'boolean',
        'version' => 'integer',
        'last_updated_by' => 'integer',
    ];

    public static function actingAs(?int $userId, ?string $reason = null): void
    {
        SettingActor::set($userId, $reason);
    }

    public static function clearActor(): void
    {
        SettingActor::clear();
    }

    public static function withActor(?int $userId, callable $callback, ?string $reason = null): mixed
    {
        return SettingActor::withActor($userId, $callback, $reason);
    }

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            if ($setting->version === null) {
                $setting->version = 1;
            }
            self::applyActorMetadata($setting);
        });

        static::updating(function (self $setting): void {
            if ($setting->isDirty('value')) {
                $setting->version = (int) ($setting->getOriginal('version') ?? 0) + 1;
                self::applyActorMetadata($setting);
            }
        });

        static::saved(function (self $setting): void {
            if (! $setting->wasRecentlyCreated && ! $setting->wasChanged('value')) {
                return;
            }

            $wasSecret = (bool) $setting->is_secret;
            $oldRaw = $setting->wasRecentlyCreated ? null : $setting->getOriginal('value');
            $newRaw = $setting->value;

            SettingAudit::query()->create([
                'setting_key' => $setting->key,
                'group' => $setting->group,
                'audit_category' => self::lookupAuditCategory($setting->key),
                'old_value' => $wasSecret ? null : $oldRaw,
                'new_value' => $wasSecret ? null : $newRaw,
                'old_version' => $setting->wasRecentlyCreated ? null : (int) ($setting->getOriginal('version') ?? 0),
                'new_version' => (int) $setting->version,
                'actor_user_id' => $setting->last_updated_by,
                'actor_ip' => SettingActor::currentIp(),
                'actor_role' => SettingActor::currentRole(),
                'reason' => SettingActor::currentReason(),
                'was_secret' => $wasSecret,
                'changed_at' => now(),
            ]);
        });
    }

    private static function applyActorMetadata(self $setting): void
    {
        if ($setting->last_updated_by === null) {
            $setting->last_updated_by = SettingActor::currentActorId();
        }
    }

    private static function lookupAuditCategory(string $key): ?string
    {
        if (! app()->bound(SettingRegistry::class)) {
            return null;
        }

        return app(SettingRegistry::class)->get($key)?->auditCategory;
    }

    /**
     * Get a setting value by key
     */
    public static function get(string $key, $default = null)
    {
        if (app()->bound(SettingRegistry::class) && app(SettingRegistry::class)->has($key)) {
            $value = app(SettingsManager::class)->get($key);

            return $value ?? $default;
        }

        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        // Cast value based on type
        switch ($setting->type) {
            case self::TYPE_BOOLEAN:
                return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
            case self::TYPE_INTEGER:
                return (int) $setting->value;
            case self::TYPE_FLOAT:
                return (float) $setting->value;
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
        if (app()->bound(SettingRegistry::class) && app(SettingRegistry::class)->has($key)) {
            app(SettingsManager::class)->set($key, $value);

            return static::where('key', $key)->first() ?? new self;
        }

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
        $settings = static::query()->where('group', $group)->getModels();
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
