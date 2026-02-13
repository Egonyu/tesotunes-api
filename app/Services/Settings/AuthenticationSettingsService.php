<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

class AuthenticationSettingsService
{
    /**
     * Get authentication settings from cache or database
     */
    public function getSettings(): array
    {
        return Cache::remember('authentication_settings', 3600, function () {
            return [
                'general' => $this->getGeneralSettings(),
                'user_login' => $this->getUserLoginSettings(),
                'artist_login' => $this->getArtistLoginSettings(),
                'social' => $this->getSocialSettings(),
            ];
        });
    }

    /**
     * Get general authentication settings
     */
    public function getGeneralSettings(): array
    {
        return [
            'two_factor_enabled' => Setting::get('auth_two_factor_enabled', false),
            'password_min_length' => Setting::get('auth_password_min_length', 8),
            'password_require_special_char' => Setting::get('auth_password_require_special_char', true),
            'password_require_number' => Setting::get('auth_password_require_number', true),
            'password_require_uppercase' => Setting::get('auth_password_require_uppercase', true),
            'session_lifetime' => Setting::get('auth_session_lifetime', 120),
            'remember_me_enabled' => Setting::get('auth_remember_me_enabled', true),
        ];
    }

    /**
     * Get user login settings
     */
    public function getUserLoginSettings(): array
    {
        return [
            'email_login_enabled' => Setting::get('auth_email_login_enabled', true),
            'phone_login_enabled' => Setting::get('auth_phone_login_enabled', true),
            'max_login_attempts' => Setting::get('auth_max_login_attempts', 5),
            'lockout_duration' => Setting::get('auth_lockout_duration', 15),
            'require_email_verification' => Setting::get('auth_require_email_verification', true),
        ];
    }

    /**
     * Get artist login settings
     */
    public function getArtistLoginSettings(): array
    {
        return [
            'artist_verification_required' => Setting::get('auth_artist_verification_required', true),
            'artist_approval_required' => Setting::get('auth_artist_approval_required', true),
            'artist_kyc_required' => Setting::get('auth_artist_kyc_required', false),
        ];
    }

    /**
     * Get social login settings
     */
    public function getSocialSettings(): array
    {
        return [
            'google_login_enabled' => Setting::get('auth_google_login_enabled', false),
            'facebook_login_enabled' => Setting::get('auth_facebook_login_enabled', false),
            'twitter_login_enabled' => Setting::get('auth_twitter_login_enabled', false),
            'google_client_id' => Setting::get('auth_google_client_id', ''),
            'facebook_client_id' => Setting::get('auth_facebook_client_id', ''),
            'twitter_client_id' => Setting::get('auth_twitter_client_id', ''),
        ];
    }

    /**
     * Update general authentication settings
     */
    public function updateGeneralSettings(array $data): bool
    {
        try {
            if (isset($data['two_factor_enabled'])) {
                Setting::set('auth_two_factor_enabled', (bool)$data['two_factor_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['password_min_length'])) {
                $length = (int)$data['password_min_length'];
                if ($length < 6 || $length > 32) {
                    throw new \InvalidArgumentException('Password minimum length must be between 6-32 characters');
                }
                Setting::set('auth_password_min_length', $length, Setting::TYPE_NUMBER, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['password_require_special_char'])) {
                Setting::set('auth_password_require_special_char', (bool)$data['password_require_special_char'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['password_require_number'])) {
                Setting::set('auth_password_require_number', (bool)$data['password_require_number'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['password_require_uppercase'])) {
                Setting::set('auth_password_require_uppercase', (bool)$data['password_require_uppercase'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['session_lifetime'])) {
                $lifetime = (int)$data['session_lifetime'];
                if ($lifetime < 1 || $lifetime > 1440) {
                    throw new \InvalidArgumentException('Session lifetime must be between 1-1440 minutes');
                }
                Setting::set('auth_session_lifetime', $lifetime, Setting::TYPE_NUMBER, Setting::GROUP_AUTHENTICATION);
                $this->updateEnvFile('SESSION_LIFETIME', (string)$lifetime);
            }

            if (isset($data['remember_me_enabled'])) {
                Setting::set('auth_remember_me_enabled', (bool)$data['remember_me_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            // Clear cache
            Cache::forget('authentication_settings');
            Artisan::call('config:clear');

            return true;
        } catch (\Exception $e) {
            \Log::error('General authentication settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Update user login settings
     */
    public function updateUserLoginSettings(array $data): bool
    {
        try {
            if (isset($data['email_login_enabled'])) {
                Setting::set('auth_email_login_enabled', (bool)$data['email_login_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['phone_login_enabled'])) {
                Setting::set('auth_phone_login_enabled', (bool)$data['phone_login_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['max_login_attempts'])) {
                $attempts = (int)$data['max_login_attempts'];
                if ($attempts < 1 || $attempts > 20) {
                    throw new \InvalidArgumentException('Max login attempts must be between 1-20');
                }
                Setting::set('auth_max_login_attempts', $attempts, Setting::TYPE_NUMBER, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['lockout_duration'])) {
                $duration = (int)$data['lockout_duration'];
                if ($duration < 1 || $duration > 1440) {
                    throw new \InvalidArgumentException('Lockout duration must be between 1-1440 minutes');
                }
                Setting::set('auth_lockout_duration', $duration, Setting::TYPE_NUMBER, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['require_email_verification'])) {
                Setting::set('auth_require_email_verification', (bool)$data['require_email_verification'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            // Clear cache
            Cache::forget('authentication_settings');
            Artisan::call('config:clear');

            return true;
        } catch (\Exception $e) {
            \Log::error('User login settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Update artist login settings
     */
    public function updateArtistLoginSettings(array $data): bool
    {
        try {
            if (isset($data['artist_verification_required'])) {
                Setting::set('auth_artist_verification_required', (bool)$data['artist_verification_required'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['artist_approval_required'])) {
                Setting::set('auth_artist_approval_required', (bool)$data['artist_approval_required'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['artist_kyc_required'])) {
                Setting::set('auth_artist_kyc_required', (bool)$data['artist_kyc_required'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            // Clear cache
            Cache::forget('authentication_settings');
            Artisan::call('config:clear');

            return true;
        } catch (\Exception $e) {
            \Log::error('Artist login settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Update social login settings
     */
    public function updateSocialSettings(array $data): bool
    {
        try {
            if (isset($data['google_login_enabled'])) {
                Setting::set('auth_google_login_enabled', (bool)$data['google_login_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['facebook_login_enabled'])) {
                Setting::set('auth_facebook_login_enabled', (bool)$data['facebook_login_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['twitter_login_enabled'])) {
                Setting::set('auth_twitter_login_enabled', (bool)$data['twitter_login_enabled'], Setting::TYPE_BOOLEAN, Setting::GROUP_AUTHENTICATION);
            }

            if (isset($data['google_client_id'])) {
                Setting::set('auth_google_client_id', $data['google_client_id'], Setting::TYPE_STRING, Setting::GROUP_AUTHENTICATION);
                $this->updateEnvFile('GOOGLE_CLIENT_ID', $data['google_client_id']);
            }

            if (isset($data['google_client_secret'])) {
                $this->updateEnvFile('GOOGLE_CLIENT_SECRET', $data['google_client_secret']);
            }

            if (isset($data['facebook_client_id'])) {
                Setting::set('auth_facebook_client_id', $data['facebook_client_id'], Setting::TYPE_STRING, Setting::GROUP_AUTHENTICATION);
                $this->updateEnvFile('FACEBOOK_CLIENT_ID', $data['facebook_client_id']);
            }

            if (isset($data['facebook_client_secret'])) {
                $this->updateEnvFile('FACEBOOK_CLIENT_SECRET', $data['facebook_client_secret']);
            }

            if (isset($data['twitter_client_id'])) {
                Setting::set('auth_twitter_client_id', $data['twitter_client_id'], Setting::TYPE_STRING, Setting::GROUP_AUTHENTICATION);
                $this->updateEnvFile('TWITTER_CLIENT_ID', $data['twitter_client_id']);
            }

            if (isset($data['twitter_client_secret'])) {
                $this->updateEnvFile('TWITTER_CLIENT_SECRET', $data['twitter_client_secret']);
            }

            // Clear cache
            Cache::forget('authentication_settings');
            Artisan::call('config:clear');

            return true;
        } catch (\Exception $e) {
            \Log::error('Social login settings update failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return false;
        }
    }

    /**
     * Update environment file
     */
    private function updateEnvFile(string $key, string $value): void
    {
        $envFile = base_path('.env');
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $content = file_get_contents($envFile);
        $pattern = "/^{$key}=.*/m";
        
        if (preg_match($pattern, $content)) {
            // Update existing key
            $content = preg_replace($pattern, "{$key}={$value}", $content);
        } else {
            // Add new key
            $content .= "\n{$key}={$value}";
        }
        
        file_put_contents($envFile, $content);
    }
}
