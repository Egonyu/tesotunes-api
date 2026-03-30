<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    use HandlesApiErrors;

    private const APPEARANCE_STRING_FIELDS = [
        'primary_color',
        'logo_light',
        'logo_dark',
        'favicon',
        'app_name',
        'admin_panel_name',
        'admin_panel_subtitle',
        'logo_alt',
        'logo_compact_label',
        'sacco_name',
        'sacco_tagline',
        'auth_form_title',
        'auth_form_subtitle',
        'auth_hero_title',
        'auth_hero_description',
        'auth_hero_image',
        'auth_stat_1_value',
        'auth_stat_1_label',
        'auth_stat_2_value',
        'auth_stat_2_label',
        'auth_stat_3_value',
        'auth_stat_3_label',
    ];

    /**
     * Get all platform settings
     */
    public function index()
    {
        return $this->handleApiAction(function () {
            $settings = [
                'general' => $this->getGeneralSettings(),
                'appearance' => $this->getAppearanceSettings(),
                'notifications' => $this->getNotificationSettings(),
                'security' => $this->getSecuritySettings(),
                'payments' => $this->getPaymentSettings(),
                'email' => $this->getEmailSettings(),
                'storage' => $this->getStorageSettings(),
            ];

            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        }, 'Failed to retrieve platform settings.');
    }

    /**
     * Get public platform settings used by unauthenticated surfaces.
     */
    public function publicIndex()
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'general' => [
                        'platform_name' => $this->getGeneralSettings()['platform_name'],
                        'tagline' => $this->getGeneralSettings()['tagline'],
                    ],
                    'appearance' => $this->getAppearanceSettings(),
                    'security' => [
                        'max_login_attempts' => $this->getSecuritySettings()['max_login_attempts'],
                        'lockout_duration_minutes' => $this->getSecuritySettings()['lockout_duration_minutes'],
                    ],
                ],
            ]);
        }, 'Failed to retrieve public platform settings.');
    }

    /**
     * Update platform settings
     */
    public function update(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $validator = Validator::make($request->all(), [
                'general' => 'sometimes|array',
                'appearance' => 'sometimes|array',
                'notifications' => 'sometimes|array',
                'security' => 'sometimes|array',
                'payments' => 'sometimes|array',
                'payments.artist_revenue_share' => 'sometimes|numeric|min:0|max:100',
                'email' => 'sometimes|array',
                'storage' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updated = [];

            // Update each section if provided
            if ($request->has('general')) {
                $this->updateGeneralSettings($request->input('general'));
                $updated[] = 'general';
            }

            if ($request->has('appearance')) {
                $this->updateAppearanceSettings($request->input('appearance'));
                $updated[] = 'appearance';
            }

            if ($request->has('notifications')) {
                $this->updateNotificationSettings($request->input('notifications'));
                $updated[] = 'notifications';
            }

            if ($request->has('security')) {
                $this->updateSecuritySettings($request->input('security'));
                $updated[] = 'security';
            }

            if ($request->has('payments')) {
                $this->updatePaymentSettings($request->input('payments'));
                $updated[] = 'payments';
            }

            if ($request->has('email')) {
                $this->updateEmailSettings($request->input('email'));
                $updated[] = 'email';
            }

            if ($request->has('storage')) {
                $this->updateStorageSettings($request->input('storage'));
                $updated[] = 'storage';
            }

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully.',
                'data' => ['updated' => $updated],
            ]);
        }, 'Failed to update platform settings.');
    }

    // ====================================================================
    // Private helper methods for each settings section
    // ====================================================================

    private function getGeneralSettings()
    {
        return [
            'platform_name' => $this->getStoredSetting('general', 'platform_name', config('app.name') ?: 'TesoTunes'),
            'tagline' => $this->getStoredSetting('general', 'tagline', 'Empowering Artists, Connecting Fans'),
            'support_email' => $this->getStoredSetting('general', 'support_email', config('mail.from.address') ?: 'support@tesotunes.com'),
            'default_currency' => $this->getStoredSetting('general', 'default_currency', 'UGX'),
            'timezone' => $this->getStoredSetting('general', 'timezone', config('app.timezone') ?: 'Africa/Kampala'),
            'maintenance_mode' => $this->getStoredSetting('general', 'maintenance_mode', false, 'boolean'),
            'registration_enabled' => $this->getStoredSetting('general', 'registration_enabled', true, 'boolean'),
        ];
    }

    private function getAppearanceSettings()
    {
        $defaults = [
            'primary_color' => '#10B981',
            'logo_light' => '',
            'logo_dark' => '',
            'favicon' => '',
            'app_name' => 'TesoTunes',
            'admin_panel_name' => 'Admin Panel',
            'admin_panel_subtitle' => 'Platform operations',
            'logo_alt' => 'TesoTunes',
            'logo_compact_label' => 'T',
            'sacco_name' => 'TesoTunes SACCO',
            'sacco_tagline' => 'Artist Finance Platform',
            'auth_form_title' => 'Welcome back',
            'auth_form_subtitle' => 'Sign in to continue listening to your favorite music',
            'auth_hero_title' => 'Discover East African Music',
            'auth_hero_description' => 'Stream millions of songs, discover new artists, and support the sounds of East Africa.',
            'auth_hero_image' => '',
            'auth_stat_1_value' => '10K+',
            'auth_stat_1_label' => 'Songs',
            'auth_stat_2_value' => '500+',
            'auth_stat_2_label' => 'Artists',
            'auth_stat_3_value' => '50K+',
            'auth_stat_3_label' => 'Users',
        ];

        $settings = [];
        foreach ($defaults as $key => $default) {
            $settings[$key] = $this->getStoredSetting('appearance', $key, $default);
        }

        return $settings;
    }

    private function getNotificationSettings()
    {
        return [
            'push_enabled' => Cache::get('settings.notifications.push_enabled', true),
            'email_enabled' => Cache::get('settings.notifications.email_enabled', true),
            'sms_enabled' => Cache::get('settings.notifications.sms_enabled', false),
            'digest_frequency' => Cache::get('settings.notifications.digest_frequency', 'daily'),
        ];
    }

    private function getSecuritySettings()
    {
        return [
            'two_factor_required' => $this->getStoredSetting('security', 'two_factor_required', false, 'boolean'),
            'password_min_length' => $this->getStoredSetting('security', 'password_min_length', 8, 'integer'),
            'session_timeout_minutes' => $this->getStoredSetting('security', 'session_timeout_minutes', 120, 'integer'),
            'max_login_attempts' => (int) (Setting::get('auth_max_login_attempts', $this->getStoredSetting('security', 'max_login_attempts', 5, 'integer')) ?? 5),
            'lockout_duration_minutes' => (int) (Setting::get('auth_lockout_duration', $this->getStoredSetting('security', 'lockout_duration_minutes', 15, 'integer')) ?? 15),
        ];
    }

    private function getPaymentSettings()
    {
        return [
            'mtn_enabled' => (bool) Cache::get('settings.payments.mtn_enabled', false),
            'mtn_api_key' => Cache::get('settings.payments.mtn_api_key') ?: '',
            'airtel_enabled' => (bool) Cache::get('settings.payments.airtel_enabled', false),
            'airtel_api_key' => Cache::get('settings.payments.airtel_api_key') ?: '',
            'zengapay_enabled' => (bool) Cache::get('settings.payments.zengapay_enabled', false),
            'zengapay_merchant_id' => Cache::get('settings.payments.zengapay_merchant_id') ?: '',
            'zengapay_api_key' => Cache::get('settings.payments.zengapay_api_key') ?: '',
            'artist_revenue_share' => (float) Setting::get(
                'artist_revenue_share',
                (float) Cache::get('settings.payments.artist_revenue_share', 70)
            ),
        ];
    }

    private function getEmailSettings()
    {
        return [
            'smtp_host' => Cache::get('settings.email.smtp_host', config('mail.mailers.smtp.host') ?: ''),
            'smtp_port' => Cache::get('settings.email.smtp_port', config('mail.mailers.smtp.port') ?: 587),
            'smtp_username' => Cache::get('settings.email.smtp_username', config('mail.mailers.smtp.username') ?: ''),
            'smtp_from_name' => Cache::get('settings.email.smtp_from_name', config('mail.from.name') ?: 'TesoTunes'),
            'smtp_from_email' => Cache::get('settings.email.smtp_from_email', config('mail.from.address') ?: 'noreply@tesotunes.com'),
        ];
    }

    private function getStorageSettings()
    {
        return [
            'driver' => Cache::get('settings.storage.driver', config('filesystems.default', 's3')),
            'max_upload_mb' => Cache::get('settings.storage.max_upload_mb', 100),
            'allowed_audio_formats' => Cache::get('settings.storage.allowed_audio_formats', 'mp3,wav,flac,aac'),
            'allowed_image_formats' => Cache::get('settings.storage.allowed_image_formats', 'jpg,jpeg,png,webp'),
        ];
    }

    // ====================================================================
    // Update methods for each section
    // ====================================================================

    private function updateGeneralSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $this->storeSetting('general', $key, $value, is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_STRING);
        }
    }

    private function updateAppearanceSettings(array $data)
    {
        foreach ($data as $key => $value) {
            if (! in_array($key, self::APPEARANCE_STRING_FIELDS, true)) {
                continue;
            }

            $this->storeSetting('appearance', $key, (string) $value, Setting::TYPE_STRING);
        }
    }

    private function updateNotificationSettings(array $data)
    {
        foreach ($data as $key => $value) {
            Cache::put("settings.notifications.{$key}", $value, now()->addYears(1));
        }
    }

    private function updateSecuritySettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_INTEGER;
            $this->storeSetting('security', $key, $value, $type);

            if ($key === 'max_login_attempts') {
                Setting::set('auth_max_login_attempts', (int) $value, Setting::TYPE_INTEGER, Setting::GROUP_SECURITY);
            }

            if ($key === 'lockout_duration_minutes') {
                Setting::set('auth_lockout_duration', (int) $value, Setting::TYPE_INTEGER, Setting::GROUP_SECURITY);
            }
        }
    }

    private function updatePaymentSettings(array $data)
    {
        foreach ($data as $key => $value) {
            if ($key === 'artist_revenue_share') {
                $normalizedValue = max(0, min(100, (float) $value));

                Setting::set(
                    'artist_revenue_share',
                    (string) $normalizedValue,
                    Setting::TYPE_STRING,
                    Setting::GROUP_ARTISTS
                );

                Cache::put("settings.payments.{$key}", $normalizedValue, now()->addYears(1));

                continue;
            }

            Cache::put("settings.payments.{$key}", $value, now()->addYears(1));
        }
    }

    private function updateEmailSettings(array $data)
    {
        foreach ($data as $key => $value) {
            Cache::put("settings.email.{$key}", $value, now()->addYears(1));
        }
    }

    private function updateStorageSettings(array $data)
    {
        foreach ($data as $key => $value) {
            Cache::put("settings.storage.{$key}", $value, now()->addYears(1));
        }
    }

    private function getStoredSetting(string $section, string $key, mixed $default, string $type = 'string'): mixed
    {
        $cacheKey = "settings.{$section}.{$key}";
        $stored = Setting::get("{$section}_{$key}");

        if ($stored !== null) {
            Cache::put($cacheKey, $stored, now()->addYears(1));

            return match ($type) {
                'boolean' => (bool) $stored,
                'integer' => (int) $stored,
                default => $stored,
            };
        }

        $cached = Cache::get($cacheKey, $default);

        return match ($type) {
            'boolean' => (bool) $cached,
            'integer' => (int) $cached,
            default => $cached,
        };
    }

    private function storeSetting(string $section, string $key, mixed $value, string $type): void
    {
        Setting::set("{$section}_{$key}", $value, $type, match ($section) {
            'appearance' => Setting::GROUP_GENERAL,
            'security' => Setting::GROUP_SECURITY,
            default => Setting::GROUP_GENERAL,
        });

        Cache::put("settings.{$section}.{$key}", $value, now()->addYears(1));
    }
}
