<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Sacco\SaccoSettings;
use App\Services\EnvironmentSettingsService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        'homepage_theme',
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
                'users' => $this->getUserSettings(),
                'credits' => $this->getCreditSettings(),
                'notifications' => $this->getNotificationSettings(),
                'mobile' => $this->getMobileVerificationSettings(),
                'security' => $this->getSecuritySettings(),
                'payments' => $this->getPaymentSettings(),
                'email' => $this->getEmailSettings(),
                'storage' => $this->getStorageSettings(),
                'sacco' => $this->getSaccoSettings(),
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
                'appearance.homepage_theme' => 'sometimes|string|in:classic_home,curated_home',
                'users' => 'sometimes|array',
                'credits' => 'sometimes|array',
                'notifications' => 'sometimes|array',
                'mobile' => 'sometimes|array',
                'security' => 'sometimes|array',
                'payments' => 'sometimes|array',
                'payments.artist_revenue_share' => 'sometimes|numeric|min:0|max:100',
                'email' => 'sometimes|array',
                'storage' => 'sometimes|array',
                'sacco' => 'sometimes|array',
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

            if ($request->has('users')) {
                $this->updateUserSettings($request->input('users'));
                $updated[] = 'users';
            }

            if ($request->has('credits')) {
                $this->updateCreditSettings($request->input('credits'));
                $updated[] = 'credits';
            }

            if ($request->has('notifications')) {
                $this->updateNotificationSettings($request->input('notifications'));
                $updated[] = 'notifications';
            }

            if ($request->has('mobile')) {
                $this->updateMobileVerificationSettings($request->input('mobile'));
                $updated[] = 'mobile';
            }

            if ($request->has('security')) {
                $this->updateSecuritySettings($request->input('security'));
                $updated[] = 'security';
            }

            if ($request->has('payments')) {
                $paymentData = $request->input('payments');
                $isSuperAdmin = (bool) ($request->user()?->isSuperAdmin() ?? false);

                if (! $isSuperAdmin) {
                    foreach (['mtn_api_key', 'airtel_api_key', 'zengapay_api_key'] as $secretKey) {
                        if (! empty($paymentData[$secretKey])) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Payment API secret updates require super admin access.',
                            ], 403);
                        }
                    }
                }

                $this->updatePaymentSettings($paymentData, $isSuperAdmin);
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

            if ($request->has('sacco')) {
                $this->updateSaccoSettings($request->input('sacco'));
                $updated[] = 'sacco';
            }

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully.',
                'data' => ['updated' => $updated],
            ]);
        }, 'Failed to update platform settings.');
    }

    public function environmentIndex(EnvironmentSettingsService $environmentSettings)
    {
        return $this->handleApiAction(function () use ($environmentSettings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'scope' => 'api',
                    'file' => '.env',
                    'restart_required' => true,
                    'frontend_note' => 'This editor updates the Laravel API environment file only. Next.js public environment variables still require a rebuild or redeploy.',
                    'groups' => $environmentSettings->getEditableSettings(),
                ],
            ]);
        }, 'Failed to retrieve environment settings.');
    }

    public function updateEnvironment(Request $request, EnvironmentSettingsService $environmentSettings)
    {
        return $this->handleApiAction(function () use ($request, $environmentSettings) {
            $validator = Validator::make($request->all(), [
                'values' => 'required|array|min:1',
                ...$environmentSettings->validationRules(),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updatedKeys = $environmentSettings->update($request->input('values', []));

            return response()->json([
                'success' => true,
                'message' => 'Environment variables updated successfully.',
                'data' => [
                    'updated' => $updatedKeys,
                    'restart_required' => true,
                ],
            ]);
        }, 'Failed to update environment settings.');
    }

    // ====================================================================
    // Private helper methods for each settings section
    // ====================================================================

    private function getGeneralSettings()
    {
        return [
            'platform_name' => $this->getStoredSetting('general', 'platform_name', config('app.name') ?: 'TesoTunes'),
            'platform_url' => $this->getStoredSetting('general', 'platform_url', config('app.url') ?: ''),
            'platform_description' => $this->getStoredSetting('general', 'platform_description', ''),
            'tagline' => $this->getStoredSetting('general', 'tagline', 'Empowering Artists, Connecting Fans'),
            'support_email' => $this->getStoredSetting('general', 'support_email', config('mail.from.address') ?: 'support@tesotunes.com'),
            'admin_contact' => $this->getStoredSetting('general', 'admin_contact', config('mail.from.address') ?: 'support@tesotunes.com'),
            'default_language' => $this->getStoredSetting('general', 'default_language', 'en'),
            'default_currency' => $this->getStoredSetting('general', 'default_currency', 'UGX'),
            'timezone' => $this->getStoredSetting('general', 'timezone', config('app.timezone') ?: 'Africa/Kampala'),
            'maintenance_mode' => $this->getStoredSetting('general', 'maintenance_mode', false, 'boolean'),
            'registration_enabled' => $this->getStoredSetting('general', 'registration_enabled', true, 'boolean'),
            'music_streaming_enabled' => $this->getStoredSetting('general', 'music_streaming_enabled', true, 'boolean'),
            'music_downloads_enabled' => $this->getStoredSetting('general', 'music_downloads_enabled', true, 'boolean'),
            'events_tickets_enabled' => $this->getStoredSetting('general', 'events_tickets_enabled', true, 'boolean'),
            'awards_system_enabled' => $this->getStoredSetting('general', 'awards_system_enabled', false, 'boolean'),
            'user_comments_enabled' => $this->getStoredSetting('general', 'user_comments_enabled', true, 'boolean'),
            'artist_following_enabled' => $this->getStoredSetting('general', 'artist_following_enabled', true, 'boolean'),
            'playlists_enabled' => $this->getStoredSetting('general', 'playlists_enabled', true, 'boolean'),
            'social_sharing_enabled' => $this->getStoredSetting('general', 'social_sharing_enabled', false, 'boolean'),
            'store_enabled' => $this->getStoredSetting('general', 'store_enabled', true, 'boolean'),
            'forums_enabled' => $this->getStoredSetting('general', 'forums_enabled', false, 'boolean'),
            'polls_enabled' => $this->getStoredSetting('general', 'polls_enabled', false, 'boolean'),
            'credits_enabled' => $this->getStoredSetting('general', 'credits_enabled', true, 'boolean'),
            'email_verification_required' => $this->getStoredSetting('general', 'email_verification_required', true, 'boolean'),
            'artist_approval_required' => $this->getStoredSetting('general', 'artist_approval_required', false, 'boolean'),
            'social_login_enabled' => $this->getStoredSetting('general', 'social_login_enabled', false, 'boolean'),
            'phone_verification_enabled' => $this->getStoredSetting('general', 'phone_verification_enabled', true, 'boolean'),
            'default_user_role' => $this->getStoredSetting('general', 'default_user_role', 'user'),
            'registration_limit_per_ip' => $this->getStoredSetting('general', 'registration_limit_per_ip', 5, 'integer'),
            'verification_required_for_tickets' => $this->getStoredSetting('general', 'verification_required_for_tickets', true, 'boolean'),
            'verification_required_for_artists' => $this->getStoredSetting('general', 'verification_required_for_artists', false, 'boolean'),
        ];
    }

    private function getUserSettings()
    {
        return [
            'user_registration_enabled' => $this->getStoredSetting('users', 'user_registration_enabled', true, 'boolean'),
            'email_verification_required' => $this->getStoredSetting('users', 'email_verification_required', true, 'boolean'),
            'artist_approval_required' => $this->getStoredSetting('users', 'artist_approval_required', false, 'boolean'),
            'social_login_enabled' => $this->getStoredSetting('users', 'social_login_enabled', false, 'boolean'),
            'phone_verification_enabled' => $this->getStoredSetting('users', 'phone_verification_enabled', true, 'boolean'),
            'default_user_role' => $this->getStoredSetting('users', 'default_user_role', 'user'),
            'registration_limit_per_ip' => $this->getStoredSetting('users', 'registration_limit_per_ip', 5, 'integer'),
            'verification_required_for_tickets' => $this->getStoredSetting('users', 'verification_required_for_tickets', true, 'boolean'),
            'verification_required_for_artists' => $this->getStoredSetting('users', 'verification_required_for_artists', false, 'boolean'),
            'user_can_upload_music' => $this->getStoredSetting('users', 'user_can_upload_music', true, 'boolean'),
            'user_can_create_playlists' => $this->getStoredSetting('users', 'user_can_create_playlists', true, 'boolean'),
            'user_can_comment' => $this->getStoredSetting('users', 'user_can_comment', true, 'boolean'),
            'user_can_download' => $this->getStoredSetting('users', 'user_can_download', true, 'boolean'),
            'artist_can_create_events' => $this->getStoredSetting('users', 'artist_can_create_events', true, 'boolean'),
            'artist_can_sell_tickets' => $this->getStoredSetting('users', 'artist_can_sell_tickets', true, 'boolean'),
            'artist_can_monetize' => $this->getStoredSetting('users', 'artist_can_monetize', true, 'boolean'),
            'artist_has_analytics' => $this->getStoredSetting('users', 'artist_has_analytics', true, 'boolean'),
            'max_upload_size_mb' => $this->getStoredSetting('users', 'max_upload_size_mb', 100, 'integer'),
            'daily_upload_limit' => $this->getStoredSetting('users', 'daily_upload_limit', 10, 'integer'),
            'max_playlists_per_user' => $this->getStoredSetting('users', 'max_playlists_per_user', 50, 'integer'),
            'max_events_per_artist_monthly' => $this->getStoredSetting('users', 'max_events_per_artist_monthly', 5, 'integer'),
            'comment_character_limit' => $this->getStoredSetting('users', 'comment_character_limit', 500, 'integer'),
            'session_timeout_minutes' => $this->getStoredSetting('users', 'session_timeout_minutes', 120, 'integer'),
            'profanity_filter_enabled' => $this->getStoredSetting('users', 'profanity_filter_enabled', false, 'boolean'),
            'auto_moderate_comments' => $this->getStoredSetting('users', 'auto_moderate_comments', false, 'boolean'),
            'auto_ban_after_violations' => $this->getStoredSetting('users', 'auto_ban_after_violations', 3, 'integer'),
            'warnings_before_ban' => $this->getStoredSetting('users', 'warnings_before_ban', 2, 'integer'),
            'spam_detection_enabled' => $this->getStoredSetting('users', 'spam_detection_enabled', false, 'boolean'),
            'rate_limiting_enabled' => $this->getStoredSetting('users', 'rate_limiting_enabled', true, 'boolean'),
            'ip_blocking_enabled' => $this->getStoredSetting('users', 'ip_blocking_enabled', false, 'boolean'),
            'moderation_email_notifications' => $this->getStoredSetting('users', 'moderation_email_notifications', true, 'boolean'),
        ];
    }

    private function getCreditSettings()
    {
        return [
            'credits_enabled' => $this->getStoredSetting('credits', 'credits_enabled', true, 'boolean'),
            'credits_per_song_upload' => $this->getStoredSetting('credits', 'credits_per_song_upload', 5, 'integer'),
            'credits_per_event_ticket' => $this->getStoredSetting('credits', 'credits_per_event_ticket', 10, 'integer'),
            'credit_purchase_enabled' => $this->getStoredSetting('credits', 'credit_purchase_enabled', true, 'boolean'),
            'credit_to_ugx_rate' => $this->getStoredSetting('credits', 'credit_to_ugx_rate', 100, 'integer'),
            'package_1_credits' => $this->getStoredSetting('credits', 'package_1_credits', 100, 'integer'),
            'package_1_price' => $this->getStoredSetting('credits', 'package_1_price', 10000, 'integer'),
            'package_1_active' => $this->getStoredSetting('credits', 'package_1_active', true, 'boolean'),
            'package_2_credits' => $this->getStoredSetting('credits', 'package_2_credits', 500, 'integer'),
            'package_2_price' => $this->getStoredSetting('credits', 'package_2_price', 50000, 'integer'),
            'package_2_active' => $this->getStoredSetting('credits', 'package_2_active', true, 'boolean'),
            'package_3_credits' => $this->getStoredSetting('credits', 'package_3_credits', 1000, 'integer'),
            'package_3_price' => $this->getStoredSetting('credits', 'package_3_price', 100000, 'integer'),
            'package_3_active' => $this->getStoredSetting('credits', 'package_3_active', true, 'boolean'),
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
            'homepage_theme' => 'classic_home',
        ];

        $settings = [];
        foreach ($defaults as $key => $default) {
            $settings[$key] = $this->getStoredSetting('appearance', $key, $default);
        }

        if (! in_array($settings['homepage_theme'], ['classic_home', 'curated_home'], true)) {
            $settings['homepage_theme'] = 'classic_home';
        }

        return $settings;
    }

    private function getNotificationSettings()
    {
        return [
            'push_enabled' => $this->getStoredSetting('notifications', 'push_enabled', true, 'boolean'),
            'email_enabled' => $this->getStoredSetting('notifications', 'email_enabled', true, 'boolean'),
            'sms_enabled' => $this->getStoredSetting('notifications', 'sms_enabled', false, 'boolean'),
            'digest_frequency' => $this->getStoredSetting('notifications', 'digest_frequency', 'daily'),
        ];
    }

    private function getMobileVerificationSettings()
    {
        return [
            'mobile_verification_enabled' => $this->getStoredSetting('mobile', 'mobile_verification_enabled', true, 'boolean'),
            'mobile_verification_required_for_events' => $this->getStoredSetting('mobile', 'mobile_verification_required_for_events', false, 'boolean'),
            'mobile_verification_required_for_artists' => $this->getStoredSetting('mobile', 'mobile_verification_required_for_artists', false, 'boolean'),
            'sms_provider' => $this->getStoredSetting('mobile', 'sms_provider', 'local'),
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
            'enable_session_timeout' => $this->getStoredSetting('security', 'enable_session_timeout', true, 'boolean'),
            'log_security_events' => $this->getStoredSetting('security', 'log_security_events', true, 'boolean'),
            'log_failed_logins' => $this->getStoredSetting('security', 'log_failed_logins', true, 'boolean'),
            'log_password_changes' => $this->getStoredSetting('security', 'log_password_changes', true, 'boolean'),
        ];
    }

    private function getPaymentSettings()
    {
        return [
            'mtn_enabled' => $this->getStoredSetting('payments', 'mtn_enabled', false, 'boolean'),
            'mtn_api_key' => '',
            'airtel_enabled' => $this->getStoredSetting('payments', 'airtel_enabled', false, 'boolean'),
            'airtel_api_key' => '',
            'zengapay_enabled' => $this->getStoredSetting('payments', 'zengapay_enabled', false, 'boolean'),
            'zengapay_merchant_id' => $this->getStoredSetting('payments', 'zengapay_merchant_id', ''),
            'zengapay_api_key' => '',
            'artist_revenue_share' => (float) Setting::get(
                'artist_revenue_share',
                (float) $this->getStoredSetting('payments', 'artist_revenue_share', 70, 'float')
            ),
        ];
    }

    private function getEmailSettings()
    {
        return [
            'smtp_host' => $this->getStoredSetting('email', 'smtp_host', config('mail.mailers.smtp.host') ?: ''),
            'smtp_port' => $this->getStoredSetting('email', 'smtp_port', config('mail.mailers.smtp.port') ?: 587, 'integer'),
            'smtp_username' => $this->getStoredSetting('email', 'smtp_username', config('mail.mailers.smtp.username') ?: ''),
            'smtp_from_name' => $this->getStoredSetting('email', 'smtp_from_name', config('mail.from.name') ?: 'TesoTunes'),
            'smtp_from_email' => $this->getStoredSetting('email', 'smtp_from_email', config('mail.from.address') ?: 'noreply@tesotunes.com'),
        ];
    }

    private function getStorageSettings()
    {
        return [
            'driver' => $this->getStoredSetting('storage', 'driver', config('filesystems.default', 's3')),
            'max_upload_mb' => $this->getStoredSetting('storage', 'max_upload_mb', 100, 'integer'),
            'allowed_audio_formats' => $this->getStoredSetting('storage', 'allowed_audio_formats', 'mp3,wav,flac,aac'),
            'allowed_image_formats' => $this->getStoredSetting('storage', 'allowed_image_formats', 'jpg,jpeg,png,webp'),
        ];
    }

    private function getSaccoSettings()
    {
        return [
            'sacco_name' => SaccoSettings::getValue('sacco_name', 'TesoTunes SACCO'),
            'sacco_tagline' => SaccoSettings::getValue('sacco_tagline', 'Artist Finance Platform'),
            'share_price_ugx' => SaccoSettings::getValue('share_price_ugx', 50000),
            'minimum_savings_balance_ugx' => SaccoSettings::getValue('minimum_savings_balance_ugx', 50000),
            'default_join_deposit_ugx' => SaccoSettings::getValue('default_join_deposit_ugx', 50000),
            'default_join_shares' => SaccoSettings::getValue('default_join_shares', 5),
            'minimum_initial_shares' => SaccoSettings::getValue('minimum_initial_shares', 5),
            'monthly_savings_target_ugx' => SaccoSettings::getValue('monthly_savings_target_ugx', 500000),
            'annual_interest_rate' => SaccoSettings::getValue('annual_interest_rate', 12),
            'annual_dividend_rate' => SaccoSettings::getValue('annual_dividend_rate', 8),
            'max_loan_multiplier' => SaccoSettings::getValue('max_loan_multiplier', 3),
            'guest_title' => SaccoSettings::getValue('guest_title', 'Join Our Artist SACCO'),
            'guest_description' => SaccoSettings::getValue('guest_description', 'A savings and credit cooperative designed exclusively for music artists. Save together, grow together.'),
            'member_title' => SaccoSettings::getValue('member_title', 'Welcome Back, Member!'),
            'member_description' => SaccoSettings::getValue('member_description', 'Manage your savings, shares, and loans. Build your financial future with fellow artists.'),
            'cta_title' => SaccoSettings::getValue('cta_title', 'Ready to Join?'),
            'cta_description' => SaccoSettings::getValue('cta_description', 'Becoming a member is easy. Start with a minimum of UGX 50,000 and begin your journey to financial growth with fellow artists.'),
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

    private function updateUserSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING);
            $this->storeSetting('users', $key, $value, $type);
        }
    }

    private function updateCreditSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING);
            $this->storeSetting('credits', $key, $value, $type);
        }
    }

    private function updateMobileVerificationSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING);
            $this->storeSetting('mobile', $key, $value, $type);
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
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_STRING;
            $this->storeSetting('notifications', $key, $value, $type);
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

    private function updatePaymentSettings(array $data, bool $canUpdateSecrets)
    {
        foreach ($data as $key => $value) {
            if ($key === 'artist_revenue_share') {
                $normalizedValue = max(0, min(100, (float) $value));

                Setting::set(
                    'artist_revenue_share',
                    (string) $normalizedValue,
                    Setting::TYPE_FLOAT,
                    Setting::GROUP_ARTISTS
                );

                Cache::forget('settings.artists');

                continue;
            }

            if (in_array($key, ['mtn_api_key', 'airtel_api_key', 'zengapay_api_key'], true) && ! $canUpdateSecrets) {
                continue;
            }

            if (in_array($key, ['mtn_api_key', 'airtel_api_key', 'zengapay_api_key'], true) && trim((string) $value) === '') {
                continue;
            }

            $type = match ($key) {
                'zengapay_merchant_id' => Setting::TYPE_STRING,
                default => is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING),
            };

            if (in_array($key, ['mtn_api_key', 'airtel_api_key', 'zengapay_api_key'], true)) {
                $this->storeSetting('payments', $key, Crypt::encryptString((string) $value), Setting::TYPE_STRING);

                continue;
            }

            $this->storeSetting('payments', $key, $value, $type);
        }
    }

    private function updateEmailSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING);
            $this->storeSetting('email', $key, $value, $type);
        }
    }

    private function updateStorageSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING);
            $this->storeSetting('storage', $key, $value, $type);
        }
    }

    private function updateSaccoSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = match ($key) {
                'annual_interest_rate', 'annual_dividend_rate', 'max_loan_multiplier' => 'float',
                default => is_bool($value) ? Setting::TYPE_BOOLEAN : (is_numeric($value) ? Setting::TYPE_INTEGER : Setting::TYPE_STRING),
            };
            SaccoSettings::setValue($key, $value, $type);
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
                'float' => (float) $stored,
                default => $stored,
            };
        }

        $cached = Cache::get($cacheKey, $default);

        return match ($type) {
            'boolean' => (bool) $cached,
            'integer' => (int) $cached,
            'float' => (float) $cached,
            default => $cached,
        };
    }

    private function storeSetting(string $section, string $key, mixed $value, string $type): void
    {
        Setting::set("{$section}_{$key}", $value, $type, match ($section) {
            'appearance' => Setting::GROUP_GENERAL,
            'email' => Setting::GROUP_EMAIL,
            'users' => Setting::GROUP_USERS,
            'credits' => Setting::GROUP_CREDITS,
            'payments' => Setting::GROUP_PAYMENTS,
            'notifications' => Setting::GROUP_NOTIFICATIONS,
            'mobile' => Setting::GROUP_MOBILE,
            'security' => Setting::GROUP_SECURITY,
            'storage' => Setting::GROUP_STORAGE,
            default => Setting::GROUP_GENERAL,
        });

        Cache::put("settings.{$section}.{$key}", $value, now()->addYears(1));
    }
}
