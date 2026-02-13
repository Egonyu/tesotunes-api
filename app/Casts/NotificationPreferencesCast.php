<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Custom cast for user notification preferences
 *
 * Used for: User settings for notification types
 *
 * Validates:
 * - Must be array of preferences
 * - Each preference has: type, email, push, sms (booleans)
 * - Valid notification types defined
 * - Quiet hours format validation (HH:MM-HH:MM)
 * - Email/push/sms must be booleans
 *
 * Example structure:
 * {
 *   "new_follower": {"email": true, "push": true, "sms": false},
 *   "new_like": {"email": false, "push": true, "sms": false},
 *   "new_comment": {"email": true, "push": true, "sms": false},
 *   "quiet_hours": {"enabled": true, "start": "22:00", "end": "08:00"}
 * }
 */
class NotificationPreferencesCast implements CastsAttributes
{
    // Valid notification types
    const NOTIFICATION_TYPES = [
        'new_follower',
        'new_like',
        'new_comment',
        'new_share',
        'playlist_activity',
        'artist_release',
        'comment_reply',
        'mentioned_in_share',
        'collaboration_invite',
        'collaboration_accepted',
        'collaboration_declined',
        'payment_received',
        'payout_approved',
        'payout_completed',
        'song_approved',
        'song_rejected',
        'distribution_completed',
        'subscription_expiring',
        'subscription_expired',
        'weekly_stats',
        'monthly_report',
        'credit_earned',
        'award_nomination',
        'event_reminder',
        'system_announcement',
    ];

    // Valid delivery channels
    const DELIVERY_CHANNELS = ['email', 'push', 'sms'];

    // Default preferences (all enabled by default except SMS)
    const DEFAULTS = [
        'email' => true,
        'push' => true,
        'sms' => false,
    ];

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return $this->getDefaults();
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->getDefaults();
        }

        if (!is_array($decoded)) {
            return $this->getDefaults();
        }

        // Merge with defaults to ensure all notification types exist
        return array_merge($this->getDefaults(), $decoded);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            return json_encode($this->getDefaults());
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("Notification preferences must be an array");
        }

        $validated = $this->validate($value);

        return json_encode($validated);
    }

    /**
     * Validate notification preferences structure
     */
    protected function validate(array $preferences): array
    {
        $validated = [];

        foreach ($preferences as $key => $setting) {
            // Handle quiet_hours separately
            if ($key === 'quiet_hours') {
                $validated['quiet_hours'] = $this->validateQuietHours($setting);
                continue;
            }

            // Handle global_mute
            if ($key === 'global_mute') {
                if (!is_bool($setting)) {
                    throw new InvalidArgumentException("global_mute must be a boolean");
                }
                $validated['global_mute'] = $setting;
                continue;
            }

            // Validate notification type
            if (!in_array($key, self::NOTIFICATION_TYPES)) {
                throw new InvalidArgumentException("Invalid notification type: {$key}. Must be one of: " . implode(', ', self::NOTIFICATION_TYPES));
            }

            // Validate channels
            if (!is_array($setting)) {
                throw new InvalidArgumentException("Notification preference for '{$key}' must be an array");
            }

            $validatedSetting = [];
            foreach (self::DELIVERY_CHANNELS as $channel) {
                if (isset($setting[$channel])) {
                    if (!is_bool($setting[$channel])) {
                        throw new InvalidArgumentException("Notification preference '{$key}.{$channel}' must be a boolean");
                    }
                    $validatedSetting[$channel] = $setting[$channel];
                } else {
                    // Use default if not specified
                    $validatedSetting[$channel] = self::DEFAULTS[$channel];
                }
            }

            $validated[$key] = $validatedSetting;
        }

        return $validated;
    }

    /**
     * Validate quiet hours configuration
     */
    protected function validateQuietHours(mixed $quietHours): array
    {
        if (!is_array($quietHours)) {
            throw new InvalidArgumentException("Quiet hours must be an array");
        }

        $validated = [
            'enabled' => false,
            'start' => '22:00',
            'end' => '08:00',
        ];

        // Validate enabled flag
        if (isset($quietHours['enabled'])) {
            if (!is_bool($quietHours['enabled'])) {
                throw new InvalidArgumentException("Quiet hours 'enabled' must be a boolean");
            }
            $validated['enabled'] = $quietHours['enabled'];
        }

        // Validate start time
        if (isset($quietHours['start'])) {
            $start = $quietHours['start'];
            if (!$this->isValidTimeFormat($start)) {
                throw new InvalidArgumentException("Quiet hours 'start' must be in HH:MM format");
            }
            $validated['start'] = $start;
        }

        // Validate end time
        if (isset($quietHours['end'])) {
            $end = $quietHours['end'];
            if (!$this->isValidTimeFormat($end)) {
                throw new InvalidArgumentException("Quiet hours 'end' must be in HH:MM format");
            }
            $validated['end'] = $end;
        }

        return $validated;
    }

    /**
     * Validate time format (HH:MM)
     */
    protected function isValidTimeFormat(string $time): bool
    {
        return (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time);
    }

    /**
     * Get default preferences
     */
    protected function getDefaults(): array
    {
        $defaults = [];

        foreach (self::NOTIFICATION_TYPES as $type) {
            $defaults[$type] = self::DEFAULTS;
        }

        $defaults['quiet_hours'] = [
            'enabled' => false,
            'start' => '22:00',
            'end' => '08:00',
        ];

        $defaults['global_mute'] = false;

        return $defaults;
    }

    /**
     * Check if user should receive a notification
     */
    public static function shouldReceive(array $preferences, string $notificationType, string $channel = 'push'): bool
    {
        // Check global mute
        if (isset($preferences['global_mute']) && $preferences['global_mute'] === true) {
            return false;
        }

        // Check quiet hours for push and sms
        if (in_array($channel, ['push', 'sms']) && isset($preferences['quiet_hours'])) {
            if (self::isQuietHours($preferences['quiet_hours'])) {
                return false;
            }
        }

        // Check specific preference
        if (!isset($preferences[$notificationType])) {
            return self::DEFAULTS[$channel] ?? false;
        }

        return $preferences[$notificationType][$channel] ?? false;
    }

    /**
     * Check if currently in quiet hours
     */
    public static function isQuietHours(array $quietHours): bool
    {
        if (!isset($quietHours['enabled']) || $quietHours['enabled'] !== true) {
            return false;
        }

        $now = now()->format('H:i');
        $start = $quietHours['start'] ?? '22:00';
        $end = $quietHours['end'] ?? '08:00';

        // Handle overnight quiet hours (e.g., 22:00 to 08:00)
        if ($start > $end) {
            return $now >= $start || $now < $end;
        }

        // Handle same-day quiet hours (e.g., 12:00 to 14:00)
        return $now >= $start && $now < $end;
    }

    /**
     * Enable all notifications for a specific type
     */
    public static function enableAll(array $preferences, string $notificationType): array
    {
        if (!in_array($notificationType, self::NOTIFICATION_TYPES)) {
            throw new InvalidArgumentException("Invalid notification type: {$notificationType}");
        }

        $preferences[$notificationType] = [
            'email' => true,
            'push' => true,
            'sms' => true,
        ];

        return $preferences;
    }

    /**
     * Disable all notifications for a specific type
     */
    public static function disableAll(array $preferences, string $notificationType): array
    {
        if (!in_array($notificationType, self::NOTIFICATION_TYPES)) {
            throw new InvalidArgumentException("Invalid notification type: {$notificationType}");
        }

        $preferences[$notificationType] = [
            'email' => false,
            'push' => false,
            'sms' => false,
        ];

        return $preferences;
    }

    /**
     * Set channel preference for a notification type
     */
    public static function setChannelPreference(array $preferences, string $notificationType, string $channel, bool $enabled): array
    {
        if (!in_array($notificationType, self::NOTIFICATION_TYPES)) {
            throw new InvalidArgumentException("Invalid notification type: {$notificationType}");
        }

        if (!in_array($channel, self::DELIVERY_CHANNELS)) {
            throw new InvalidArgumentException("Invalid channel: {$channel}");
        }

        if (!isset($preferences[$notificationType])) {
            $preferences[$notificationType] = self::DEFAULTS;
        }

        $preferences[$notificationType][$channel] = $enabled;

        return $preferences;
    }

    /**
     * Get enabled channels for a notification type
     */
    public static function getEnabledChannels(array $preferences, string $notificationType): array
    {
        if (!isset($preferences[$notificationType])) {
            return array_keys(array_filter(self::DEFAULTS));
        }

        return array_keys(array_filter($preferences[$notificationType]));
    }
}
