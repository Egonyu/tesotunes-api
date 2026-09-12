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

            /*
             * Guest ticket checkout, off by default.
             *
             * A guest buys against a throwaway account they can never sign into,
             * so the only copy of their ticket is the confirmation email. While
             * transactional mail is undeliverable that is a dead end: they pay
             * and have no route to the QR code they are scanned by at the gate.
             * Signed-in buyers are unaffected — their ticket lives in the app.
             *
             * Turn this back on once mail is delivering, or once guests can
             * retrieve a ticket by order ID without needing email at all.
             */
            'events_guest_checkout_enabled' => ['Guest ticket checkout', false],
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
