<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_type',
        'device_name',
        'app_version',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    // Platform constants
    const PLATFORM_IOS = 'ios';

    const PLATFORM_ANDROID = 'android';

    const PLATFORM_WEB = 'web';

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeIos(Builder $query): Builder
    {
        return $query->where('platform', self::PLATFORM_IOS);
    }

    public function scopeAndroid(Builder $query): Builder
    {
        return $query->where('platform', self::PLATFORM_ANDROID);
    }

    public function scopeWeb(Builder $query): Builder
    {
        return $query->where('platform', self::PLATFORM_WEB);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeUnusedSince(Builder $query, $date): Builder
    {
        return $query->where('last_used_at', '<', $date);
    }

    // Helper methods
    public function isIos(): bool
    {
        return $this->platform === self::PLATFORM_IOS;
    }

    public function isAndroid(): bool
    {
        return $this->platform === self::PLATFORM_ANDROID;
    }

    public function isWeb(): bool
    {
        return $this->platform === self::PLATFORM_WEB;
    }

    public function activate(): bool
    {
        return $this->update(['is_active' => true]);
    }

    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    public function updateLastUsed(): bool
    {
        return $this->update(['last_used_at' => now()]);
    }

    public function getDeviceName(): ?string
    {
        return $this->device_name;
    }

    public function getDeviceModel(): ?string
    {
        return $this->device_type;
    }

    public function getOsVersion(): ?string
    {
        return null;
    }

    public function getAppVersion(): ?string
    {
        return $this->app_version;
    }

    // Static helper methods
    public static function registerToken(
        int $userId,
        string $token,
        string $platform,
        array $deviceInfo = []
    ): self {
        return static::updateOrCreate(
            [
                'user_id' => $userId,
                'token' => $token,
            ],
            [
                'platform' => $platform,
                'device_type' => $deviceInfo['model'] ?? $deviceInfo['modelName'] ?? $deviceInfo['device_type'] ?? null,
                'device_name' => $deviceInfo['name'] ?? $deviceInfo['brand'] ?? $deviceInfo['device_name'] ?? null,
                'app_version' => $deviceInfo['app_version'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );
    }

    public static function deactivateUserTokens(int $userId, ?string $exceptToken = null): int
    {
        $query = static::where('user_id', $userId);

        if ($exceptToken) {
            $query->where('token', '!=', $exceptToken);
        }

        return $query->update(['is_active' => false]);
    }

    public static function cleanupInactiveTokens(int $daysInactive = 90): int
    {
        return static::inactive()
            ->unusedSince(now()->subDays($daysInactive))
            ->delete();
    }

    public function getDeviceInfoAttribute(): array
    {
        return array_filter([
            'name' => $this->device_name,
            'model' => $this->device_type,
            'app_version' => $this->app_version,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
