<?php

namespace App\Traits;

trait ChecksNotificationPreferences
{
    /**
     * Filter channels based on the notifiable's saved notification_preferences.
     *
     * Preferences are stored as a flat per-type JSON object:
     *   { "song_approved": { "email": true, "push": true, "in_app": true }, ..., "global_mute": false }
     *
     * Falls back to allowing all channels when no preference is recorded.
     */
    protected function filterChannelsByPreference(object $notifiable, array $channels, string $module, ?string $type = null): array
    {
        $prefs = $notifiable->notificationPreferences ?? $notifiable->notification_preferences ?? [];

        if (empty($prefs)) {
            return $channels;
        }

        if ($prefs['global_mute'] ?? false) {
            return [\App\Channels\AppNotificationChannel::class];
        }

        return array_values(array_filter($channels, function (string $channel) use ($prefs, $type): bool {
            $channelKey = $this->resolveChannelKey($channel);

            // Per-type preference wins when present
            if ($type !== null && isset($prefs[$type]) && is_array($prefs[$type])) {
                return (bool) ($prefs[$type][$channelKey] ?? true);
            }

            // Default: allow
            return true;
        }));
    }

    /**
     * Map a Laravel channel class to its per-type preference sub-key.
     */
    private function resolveChannelKey(string $channel): string
    {
        return match ($channel) {
            'mail' => 'email',
            'database', \App\Channels\AppNotificationChannel::class => 'in_app',
            \App\Channels\ExpoPushChannel::class => 'push',
            default => 'in_app',
        };
    }
}
