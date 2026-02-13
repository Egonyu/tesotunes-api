<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class PodcastSettingsService
{
    /**
     * Get podcast module settings
     */
    public function getSettings(): array
    {
        return [
            // General Settings
            'podcast_module_enabled' => Setting::get('podcast_module_enabled', true),
            'podcast_allow_public_submissions' => Setting::get('podcast_allow_public_submissions', true),
            'podcast_require_approval' => Setting::get('podcast_require_approval', true),
            'podcast_auto_publish' => Setting::get('podcast_auto_publish', false),
            
            // Upload Restrictions
            'podcast_max_episode_size_mb' => Setting::get('podcast_max_episode_size_mb', 500),
            'podcast_max_episodes_per_series' => Setting::get('podcast_max_episodes_per_series', 1000),
            'podcast_allowed_formats' => Setting::get('podcast_allowed_formats', 'mp3,m4a,wav'),
            'podcast_min_duration_seconds' => Setting::get('podcast_min_duration_seconds', 60),
            'podcast_max_duration_hours' => Setting::get('podcast_max_duration_hours', 4),
            
            // Series Management
            'podcast_allow_multiple_series' => Setting::get('podcast_allow_multiple_series', true),
            'podcast_require_cover_art' => Setting::get('podcast_require_cover_art', true),
            'podcast_cover_art_min_size' => Setting::get('podcast_cover_art_min_size', 1400),
            'podcast_require_category' => Setting::get('podcast_require_category', true),
            
            // Monetization
            'podcast_monetization_enabled' => Setting::get('podcast_monetization_enabled', true),
            'podcast_ads_enabled' => Setting::get('podcast_ads_enabled', true),
            'podcast_sponsorship_enabled' => Setting::get('podcast_sponsorship_enabled', true),
            'podcast_premium_episodes_enabled' => Setting::get('podcast_premium_episodes_enabled', true),
            'podcast_creator_revenue_share' => Setting::get('podcast_creator_revenue_share', 70),
            
            // Analytics & Features
            'podcast_analytics_enabled' => Setting::get('podcast_analytics_enabled', true),
            'podcast_comments_enabled' => Setting::get('podcast_comments_enabled', true),
            'podcast_ratings_enabled' => Setting::get('podcast_ratings_enabled', true),
            'podcast_transcriptions_enabled' => Setting::get('podcast_transcriptions_enabled', false),
            'podcast_chapters_enabled' => Setting::get('podcast_chapters_enabled', true),
            
            // Distribution
            'podcast_rss_feeds_enabled' => Setting::get('podcast_rss_feeds_enabled', true),
            'podcast_apple_podcasts_integration' => Setting::get('podcast_apple_podcasts_integration', false),
            'podcast_spotify_integration' => Setting::get('podcast_spotify_integration', false),
            'podcast_google_podcasts_integration' => Setting::get('podcast_google_podcasts_integration', false),
        ];
    }

    /**
     * Update general podcast settings
     */
    public function updateGeneralSettings(array $data): bool
    {
        try {
            $settings = [
                'podcast_module_enabled' => $data['podcast_module_enabled'] ?? true,
                'podcast_allow_public_submissions' => $data['podcast_allow_public_submissions'] ?? true,
                'podcast_require_approval' => $data['podcast_require_approval'] ?? true,
                'podcast_auto_publish' => $data['podcast_auto_publish'] ?? false,
                'podcast_allow_multiple_series' => $data['podcast_allow_multiple_series'] ?? true,
            ];

            foreach ($settings as $key => $value) {
                Setting::set($key, $value, Setting::TYPE_BOOLEAN, Setting::GROUP_MODULES);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update podcast general settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update podcast upload restrictions
     */
    public function updateUploadSettings(array $data): bool
    {
        try {
            $settings = [
                'podcast_max_episode_size_mb' => $data['podcast_max_episode_size_mb'] ?? 500,
                'podcast_max_episodes_per_series' => $data['podcast_max_episodes_per_series'] ?? 1000,
                'podcast_allowed_formats' => $data['podcast_allowed_formats'] ?? 'mp3,m4a,wav',
                'podcast_min_duration_seconds' => $data['podcast_min_duration_seconds'] ?? 60,
                'podcast_max_duration_hours' => $data['podcast_max_duration_hours'] ?? 4,
                'podcast_require_cover_art' => $data['podcast_require_cover_art'] ?? true,
                'podcast_cover_art_min_size' => $data['podcast_cover_art_min_size'] ?? 1400,
                'podcast_require_category' => $data['podcast_require_category'] ?? true,
            ];

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_NUMBER : Setting::TYPE_STRING);
                Setting::set($key, $value, $type, Setting::GROUP_MODULES);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update podcast upload settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update podcast monetization settings
     */
    public function updateMonetizationSettings(array $data): bool
    {
        try {
            $settings = [
                'podcast_monetization_enabled' => $data['podcast_monetization_enabled'] ?? true,
                'podcast_ads_enabled' => $data['podcast_ads_enabled'] ?? true,
                'podcast_sponsorship_enabled' => $data['podcast_sponsorship_enabled'] ?? true,
                'podcast_premium_episodes_enabled' => $data['podcast_premium_episodes_enabled'] ?? true,
                'podcast_creator_revenue_share' => $data['podcast_creator_revenue_share'] ?? 70,
            ];

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_MODULES);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update podcast monetization settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update podcast features settings
     */
    public function updateFeaturesSettings(array $data): bool
    {
        try {
            $settings = [
                'podcast_analytics_enabled' => $data['podcast_analytics_enabled'] ?? true,
                'podcast_comments_enabled' => $data['podcast_comments_enabled'] ?? true,
                'podcast_ratings_enabled' => $data['podcast_ratings_enabled'] ?? true,
                'podcast_transcriptions_enabled' => $data['podcast_transcriptions_enabled'] ?? false,
                'podcast_chapters_enabled' => $data['podcast_chapters_enabled'] ?? true,
                'podcast_rss_feeds_enabled' => $data['podcast_rss_feeds_enabled'] ?? true,
                'podcast_apple_podcasts_integration' => $data['podcast_apple_podcasts_integration'] ?? false,
                'podcast_spotify_integration' => $data['podcast_spotify_integration'] ?? false,
                'podcast_google_podcasts_integration' => $data['podcast_google_podcasts_integration'] ?? false,
            ];

            foreach ($settings as $key => $value) {
                Setting::set($key, $value, Setting::TYPE_BOOLEAN, Setting::GROUP_MODULES);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update podcast features settings: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle podcast module on/off
     */
    public function toggleModule(bool $enabled): bool
    {
        try {
            Setting::set('podcast_module_enabled', $enabled, Setting::TYPE_BOOLEAN, Setting::GROUP_MODULES);
            
            Log::info('Podcast module toggled', [
                'enabled' => $enabled,
                'admin_id' => auth()->id()
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to toggle podcast module: ' . $e->getMessage());
            return false;
        }
    }
}
