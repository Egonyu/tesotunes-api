<?php

namespace App\Models\Concerns;

use App\Helpers\StorageHelper;
use App\Models\UserSetting;

/**
 * Backward-compatibility accessors for profile fields that were normalised
 * out of the users table into user_profiles, referral_profiles, and
 * user_settings tables.  Each accessor reads from the related model when
 * loaded, falling back to the original column value on the users row.
 */
trait HasNormalizedProfile
{
    // -------------------------------------------------------------------------
    // Profile field shims (user_profiles table)
    // -------------------------------------------------------------------------

    public function getBioAttribute($value)
    {
        return $this->profileValue('bio', $value);
    }

    public function getAvatarAttribute($value)
    {
        return $this->profileValue('avatar', $value);
    }

    public function getDateOfBirthAttribute($value)
    {
        return $this->profileValue('date_of_birth', $value);
    }

    public function getBannerAttribute($value)
    {
        return $this->profileValue('banner', $value);
    }

    public function getCountryAttribute($value)
    {
        return $this->profileValue('country', $value);
    }

    public function getCityAttribute($value)
    {
        return $this->profileValue('city', $value);
    }

    public function getTimezoneAttribute($value)
    {
        return $this->profileValue('timezone', $value);
    }

    public function getLanguageAttribute($value)
    {
        return $this->profileValue('language', $value);
    }

    public function getInstagramUrlAttribute($value)
    {
        return $this->profileValue('instagram_url', $value);
    }

    public function getTwitterUrlAttribute($value)
    {
        return $this->profileValue('twitter_url', $value);
    }

    public function getFacebookUrlAttribute($value)
    {
        return $this->profileValue('facebook_url', $value);
    }

    public function getYoutubeUrlAttribute($value)
    {
        return $this->profileValue('youtube_url', $value);
    }

    public function getTiktokUrlAttribute($value)
    {
        return $this->profileValue('tiktok_url', $value);
    }

    public function getProfileCompletionPercentageAttribute($value)
    {
        return $this->profileValue('profile_completion_percentage', $value);
    }

    public function getProfileStepsCompletedAttribute($value)
    {
        return $this->profileValue('profile_steps_completed', $value);
    }

    public function getAvatarUrlAttribute(): string
    {
        $avatar = $this->attributes['avatar'] ?? null;

        return StorageHelper::avatarUrl($avatar, $this->name);
    }

    /**
     * Resolved display name: profile > artist stage name > first+last > 'User'.
     */
    public function getDisplayNameAttribute(): string
    {
        $profileDisplayName = $this->profileValue('display_name', $this->attributes['display_name'] ?? null);
        if ($profileDisplayName) {
            return $profileDisplayName;
        }

        if ($this->artist) {
            return $this->artist->stage_name;
        }

        $firstName = $this->profileValue('first_name', $this->attributes['first_name'] ?? '');
        $lastName = $this->profileValue('last_name', $this->attributes['last_name'] ?? '');

        return trim($firstName.' '.$lastName) ?: 'User';
    }

    // -------------------------------------------------------------------------
    // Referral field shims (referral_profiles table)
    // -------------------------------------------------------------------------

    public function getReferralCodeAttribute($value)
    {
        return $this->referralValue('referral_code', $value);
    }

    public function getReferrerIdAttribute($value)
    {
        return $this->referralValue('referrer_id', $value);
    }

    public function getReferralCountAttribute($value)
    {
        return $this->referralValue('referral_count', $value);
    }

    public function getReferredAtAttribute($value)
    {
        return $this->referralValue('referred_at', $value);
    }

    // -------------------------------------------------------------------------
    // Settings field shims (user_settings table)
    // -------------------------------------------------------------------------

    public function getSettingsAttribute($value): mixed
    {
        if ($this->relationLoaded('settings')) {
            return $this->getRelation('settings');
        }

        if ($this->exists) {
            $setting = $this->settings()->first();
            if ($setting) {
                return $setting;
            }
        }

        return $value;
    }

    public function getThemePreferenceAttribute($value): string
    {
        return (string) ($this->settingsValue('theme', $value ?? 'system') ?? 'system');
    }

    public function getNotificationPreferencesAttribute($value): mixed
    {
        return $this->settingsValue('notification_preferences', $value);
    }

    public function getEmailNotificationsEnabledAttribute($value): bool
    {
        return (bool) $this->settingsValue('email_notifications', $value ?? true);
    }

    public function getSmsNotificationsEnabledAttribute($value): bool
    {
        return (bool) $this->settingsValue('sms_notifications', $value ?? true);
    }

    // -------------------------------------------------------------------------
    // Private helpers — read from related models when loaded
    // -------------------------------------------------------------------------

    protected function profileValue(string $key, mixed $fallback = null): mixed
    {
        if ($this->relationLoaded('profile') && $this->profile) {
            $value = $this->profile->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    protected function referralValue(string $key, mixed $fallback = null): mixed
    {
        if ($this->relationLoaded('referralProfile') && $this->referralProfile) {
            $value = $this->referralProfile->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    protected function settingsValue(string $key, mixed $fallback = null): mixed
    {
        $settings = $this->settings;

        if ($settings instanceof UserSetting) {
            $value = $settings->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }
}
