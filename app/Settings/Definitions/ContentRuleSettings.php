<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: content_rules — user/artist permissions, upload limits, moderation.
 * Maps to legacy "users" section content + moderation toggles.
 */
final class ContentRuleSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'content_rules';
        $cat = 'content_rules';

        // User capabilities
        $perms = [
            'user_can_upload_music' => 'Users may upload music',
            'user_can_create_playlists' => 'Users may create playlists',
            'user_can_comment' => 'Users may comment',
            'user_can_download' => 'Users may download',
            'artist_can_create_events' => 'Artists may create events',
            'artist_can_sell_tickets' => 'Artists may sell tickets',
            'artist_can_monetize' => 'Artists may monetize content',
            'artist_has_analytics' => 'Artists may view analytics',
        ];
        foreach ($perms as $k => $label) {
            Define::bool("users_{$k}", true)
                ->group($g)->subgroup('permissions')
                ->label($label)->auditCategory($cat)->register();
        }

        // Limits
        Define::int('users_max_upload_size_mb', 100)
            ->group($g)->subgroup('limits')
            ->rules(['integer', 'min:1', 'max:2048'])
            ->label('Max upload size (MB)')->auditCategory($cat)->register();
        Define::int('users_daily_upload_limit', 10)
            ->group($g)->subgroup('limits')
            ->rules(['integer', 'min:0', 'max:1000'])
            ->label('Daily uploads per user')->auditCategory($cat)->register();
        Define::int('users_max_playlists_per_user', 50)
            ->group($g)->subgroup('limits')
            ->rules(['integer', 'min:0', 'max:10000'])
            ->label('Max playlists per user')->auditCategory($cat)->register();
        Define::int('users_max_events_per_artist_monthly', 5)
            ->group($g)->subgroup('limits')
            ->rules(['integer', 'min:0', 'max:200'])
            ->label('Max events per artist per month')->auditCategory($cat)->register();
        Define::int('users_comment_character_limit', 500)
            ->group($g)->subgroup('limits')
            ->rules(['integer', 'min:1', 'max:5000'])
            ->label('Comment character limit')->auditCategory($cat)->register();

        // Moderation
        $mod = [
            'profanity_filter_enabled' => ['Profanity filter', false],
            'auto_moderate_comments' => ['Auto-moderate comments', false],
            'spam_detection_enabled' => ['Spam detection', false],
            'rate_limiting_enabled' => ['Rate limiting', true],
            'ip_blocking_enabled' => ['IP blocking', false],
            'moderation_email_notifications' => ['Email mods on flags', true],
        ];
        foreach ($mod as $k => [$label, $default]) {
            Define::bool("users_{$k}", $default)
                ->group($g)->subgroup('moderation')
                ->label($label)->auditCategory($cat)->register();
        }

        Define::int('users_auto_ban_after_violations', 3)
            ->group($g)->subgroup('moderation')
            ->rules(['integer', 'min:1', 'max:100'])
            ->label('Auto-ban after N violations')->auditCategory($cat)->register();
        Define::int('users_warnings_before_ban', 2)
            ->group($g)->subgroup('moderation')
            ->rules(['integer', 'min:0', 'max:100'])
            ->label('Warnings before ban')->auditCategory($cat)->register();
    }
}
