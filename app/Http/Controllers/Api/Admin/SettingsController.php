<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Get all platform settings
     */
    public function index()
    {
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
            'data' => $settings,
        ]);
    }

    /**
     * Update platform settings
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'general' => 'sometimes|array',
            'appearance' => 'sometimes|array',
            'notifications' => 'sometimes|array',
            'security' => 'sometimes|array',
            'payments' => 'sometimes|array',
            'email' => 'sometimes|array',
            'storage' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
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
            'message' => 'Settings updated successfully.',
            'data' => ['updated' => $updated],
        ]);
    }

    // ====================================================================
    // Private helper methods for each settings section
    // ====================================================================

    private function getGeneralSettings()
    {
        return [
            'platform_name' => Cache::get('settings.general.platform_name', config('app.name')) ?: 'TesoTunes',
            'tagline' => Cache::get('settings.general.tagline') ?: 'Empowering Artists, Connecting Fans',
            'support_email' => Cache::get('settings.general.support_email', config('mail.from.address')) ?: 'support@tesotunes.com',
            'default_currency' => Cache::get('settings.general.default_currency') ?: 'UGX',
            'timezone' => Cache::get('settings.general.timezone', config('app.timezone')) ?: 'Africa/Kampala',
            'maintenance_mode' => (bool) Cache::get('settings.general.maintenance_mode', false),
            'registration_enabled' => (bool) Cache::get('settings.general.registration_enabled', true),
        ];
    }

    private function getAppearanceSettings()
    {
        return [
            'primary_color' => Cache::get('settings.appearance.primary_color') ?: '#10B981',
            'logo_light' => Cache::get('settings.appearance.logo_light') ?: '',
            'logo_dark' => Cache::get('settings.appearance.logo_dark') ?: '',
            'favicon' => Cache::get('settings.appearance.favicon') ?: '',
        ];
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
            'two_factor_required' => Cache::get('settings.security.two_factor_required', false),
            'password_min_length' => Cache::get('settings.security.password_min_length', 8),
            'session_timeout_minutes' => Cache::get('settings.security.session_timeout_minutes', 120),
            'max_login_attempts' => Cache::get('settings.security.max_login_attempts', 5),
            'lockout_duration_minutes' => Cache::get('settings.security.lockout_duration_minutes', 15),
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
            Cache::put("settings.general.{$key}", $value, now()->addYears(1));
        }
    }

    private function updateAppearanceSettings(array $data)
    {
        foreach ($data as $key => $value) {
            Cache::put("settings.appearance.{$key}", $value, now()->addYears(1));
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
            Cache::put("settings.security.{$key}", $value, now()->addYears(1));
        }
    }

    private function updatePaymentSettings(array $data)
    {
        foreach ($data as $key => $value) {
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
}
