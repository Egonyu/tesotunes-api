<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: features — every kill switch. Consolidates the *_enabled flags
 * that were scattered across the legacy "general" section.
 */
final class FeatureSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'features';
        $cat = 'feature_flags';

        $flags = [
            'general_music_streaming_enabled' => ['Music streaming', true],
            'general_music_downloads_enabled' => ['Music downloads', true],
            'general_events_tickets_enabled' => ['Events & tickets', true],
            'general_awards_system_enabled' => ['Awards system', false],
            'general_user_comments_enabled' => ['User comments', true],
            'general_artist_following_enabled' => ['Artist following', true],
            'general_playlists_enabled' => ['Playlists', true],
            'general_social_sharing_enabled' => ['Social sharing', false],
            'general_store_enabled' => ['Store', true],
            'general_forums_enabled' => ['Forums', false],
            'general_polls_enabled' => ['Polls', false],
            'general_podcasts_enabled' => ['Podcasts', false],
            'general_promotions_enabled' => ['Promotions', false],
            'general_sacco_enabled' => ['SACCO module', false],
            'general_campaigns_enabled' => ['Campaigns', false],
            'general_edula_enabled' => ['Edula module', false],
            'general_ojokotau_enabled' => ['Ojokotau module', false],
        ];

        foreach ($flags as $key => [$label, $default]) {
            Define::bool($key, $default)
                ->group($g)->subgroup('toggles')
                ->label($label)
                ->auditCategory($cat)
                ->register();
        }
    }
}
