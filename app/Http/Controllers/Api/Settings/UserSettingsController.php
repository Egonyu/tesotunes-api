<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingsController extends Controller
{
    /**
     * GET /api/settings
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = UserSetting::firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'data' => $this->formatSettings($settings),
        ]);
    }

    /**
     * PUT /api/settings
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio.quality_wifi' => 'sometimes|string|in:low,normal,high,very_high',
            'audio.quality_mobile' => 'sometimes|string|in:low,normal,high,very_high',
            'audio.download_quality' => 'sometimes|string|in:low,normal,high,very_high',
            'audio.crossfade_enabled' => 'sometimes|boolean',
            'audio.crossfade_duration' => 'sometimes|integer|min:1|max:12',
            'audio.gapless_playback' => 'sometimes|boolean',
            'audio.normalize_volume' => 'sometimes|boolean',
            'audio.equalizer_preset' => 'sometimes|string|max:50',
            'notifications.push_enabled' => 'sometimes|boolean',
            'notifications.email_enabled' => 'sometimes|boolean',
            'notifications.new_music' => 'sometimes|boolean',
            'notifications.artist_updates' => 'sometimes|boolean',
            'notifications.playlist_updates' => 'sometimes|boolean',
            'notifications.social_updates' => 'sometimes|boolean',
            'notifications.promotional' => 'sometimes|boolean',
            'notifications.referral_updates' => 'sometimes|boolean',
            'profile.display_name' => 'sometimes|string|max:255',
            'profile.public_profile' => 'sometimes|boolean',
            'profile.show_listening_activity' => 'sometimes|boolean',
            'profile.show_followers' => 'sometimes|boolean',
            'profile.show_following' => 'sometimes|boolean',
            'downloads.wifi_only' => 'sometimes|boolean',
            'downloads.auto_download_liked' => 'sometimes|boolean',
            'downloads.storage_limit_mb' => 'sometimes|integer|min:100|max:102400',
            'privacy.data_collection' => 'sometimes|boolean',
            'privacy.personalized_ads' => 'sometimes|boolean',
            'privacy.share_listening_data' => 'sometimes|boolean',
            'language.app_language' => 'sometimes|string|max:10',
            'language.content_language' => 'sometimes|string|max:10',
            'appearance.theme' => 'sometimes|string|in:light,dark,system',
            'appearance.accent_color' => 'sometimes|string|max:20',
        ]);

        $user = $request->user();
        $settings = UserSetting::firstOrCreate(['user_id' => $user->id]);

        $audio = $validated['audio'] ?? [];
        $notifications = $validated['notifications'] ?? [];
        $profile = $validated['profile'] ?? [];

        $updates = [];

        if (isset($audio['quality_wifi'])) {
            $updates['streaming_quality_wifi'] = $audio['quality_wifi'];
        }
        if (isset($audio['quality_mobile'])) {
            $updates['streaming_quality_mobile'] = $audio['quality_mobile'];
        }
        if (isset($audio['download_quality'])) {
            $updates['download_quality'] = $audio['download_quality'];
        }
        if (isset($audio['crossfade_enabled'])) {
            $updates['autoplay_enabled'] = $audio['crossfade_enabled'];
        }
        if (isset($notifications['push_enabled'])) {
            $updates['push_notifications'] = $notifications['push_enabled'];
        }
        if (isset($notifications['email_enabled'])) {
            $updates['email_notifications'] = $notifications['email_enabled'];
        }
        if (isset($profile['public_profile'])) {
            $updates['profile_public'] = $profile['public_profile'];
        }
        if (isset($profile['show_listening_activity'])) {
            $updates['show_listening_activity'] = $profile['show_listening_activity'];
        }
        if (isset($validated['appearance']['theme'])) {
            $updates['theme'] = $validated['appearance']['theme'];
        }
        if (isset($validated['language']['app_language'])) {
            $updates['language'] = $validated['language']['app_language'];
        }

        if (! empty($updates)) {
            $settings->update($updates);
        }

        return response()->json([
            'message' => 'Settings updated.',
            'data' => $this->formatSettings($settings->fresh() ?? $settings),
        ]);
    }

    private function formatSettings(UserSetting $settings): array
    {
        $notifPrefs = is_array($settings->notification_preferences) ? $settings->notification_preferences : [];

        return [
            'profile' => [
                'display_name' => '',
                'public_profile' => (bool) ($settings->profile_public ?? true),
                'show_listening_activity' => (bool) ($settings->show_listening_activity ?? true),
                'show_followers' => (bool) ($settings->allow_followers ?? true),
                'show_following' => true,
            ],
            'notifications' => [
                'push_enabled' => (bool) ($settings->push_notifications ?? true),
                'email_enabled' => (bool) ($settings->email_notifications ?? true),
                'new_music' => (bool) ($notifPrefs['new_music'] ?? true),
                'artist_updates' => (bool) ($notifPrefs['artist_updates'] ?? true),
                'playlist_updates' => (bool) ($notifPrefs['playlist_updates'] ?? true),
                'social_updates' => (bool) ($notifPrefs['social_updates'] ?? true),
                'promotional' => (bool) ($notifPrefs['promotional'] ?? false),
                'referral_updates' => (bool) ($notifPrefs['referral_updates'] ?? true),
            ],
            'audio' => [
                'quality_wifi' => $settings->streaming_quality_wifi ?? 'high',
                'quality_mobile' => $settings->streaming_quality_mobile ?? 'normal',
                'download_quality' => $settings->download_quality ?? 'high',
                'crossfade_enabled' => false,
                'crossfade_duration' => 3,
                'gapless_playback' => true,
                'normalize_volume' => false,
                'equalizer_preset' => 'flat',
            ],
            'downloads' => [
                'wifi_only' => false,
                'auto_download_liked' => false,
                'storage_limit_mb' => 2048,
            ],
            'privacy' => [
                'data_collection' => true,
                'personalized_ads' => false,
                'share_listening_data' => (bool) ($settings->show_listening_activity ?? true),
            ],
            'language' => [
                'app_language' => $settings->language ?? 'en',
                'content_language' => 'en',
            ],
            'appearance' => [
                'theme' => $settings->theme ?? 'system',
                'accent_color' => 'purple',
            ],
        ];
    }
}
