<?php

namespace App\Traits;

trait ChecksNotificationPreferences
{
    /**
     * Filter channels based on user notification preferences.
     *
     * Checks the notifiable's notification_preferences JSON field
     * for module-level and channel-level toggles. Falls back to
     * allowing all channels if no preference is set.
     */
    protected function filterChannelsByPreference(object $notifiable, array $channels, string $module, ?string $type = null): array
    {
        $prefs = $notifiable->notification_preferences ?? [];

        if (empty($prefs)) {
            return $channels;
        }

        return array_values(array_filter($channels, function ($channel) use ($notifiable, $prefs, $module) {
            $channelKey = $this->resolveChannelKey($channel);

            // Check channel-level preferences (e.g., push_notifications.music)
            $channelPrefs = $prefs[$channelKey] ?? null;
            if (is_array($channelPrefs) && isset($channelPrefs[$module])) {
                $moduleValue = $channelPrefs[$module];

                // Supports both boolean (true/false) and array of allowed types
                if (is_bool($moduleValue)) {
                    return $moduleValue;
                }
            }

            // Check top-level toggles on User model
            if ($channelKey === 'email_notifications' && $notifiable->email_notifications_enabled === false) {
                return false;
            }

            if ($channelKey === 'sms_notifications' && $notifiable->sms_notifications_enabled === false) {
                return false;
            }

            // Default: allow
            return true;
        }));
    }

    /**
     * Map a Laravel channel class to its preference key.
     */
    private function resolveChannelKey(string $channel): string
    {
        return match ($channel) {
            'mail' => 'email_notifications',
            'database' => 'in_app_notifications',
            \App\Channels\AppNotificationChannel::class => 'in_app_notifications',
            \App\Channels\ExpoPushChannel::class => 'push_notifications',
            default => $channel,
        };
    }
}
