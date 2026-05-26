<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingRegistry;

/**
 * Group: access_auth — registration, verification, password policy, session,
 * lockout, social login. Canonical home for fields previously duplicated in
 * "general", "users", and "security" sections.
 */
final class AccessAuthSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'access_auth';
        $cat = 'access_auth';

        // Registration (canonical for general_* duplicates)
        Define::bool('users_user_registration_enabled', true)
            ->group($g)->subgroup('registration')
            ->label('Allow new signups')->auditCategory($cat)->register();

        Define::bool('users_email_verification_required', true)
            ->group($g)->subgroup('registration')
            ->label('Require email verification')->auditCategory($cat)->register();

        Define::bool('users_phone_verification_enabled', true)
            ->group($g)->subgroup('registration')
            ->label('Phone verification on signup')->auditCategory($cat)->register();

        Define::bool('users_artist_approval_required', false)
            ->group($g)->subgroup('registration')
            ->label('Manual artist approval')->auditCategory($cat)->register();

        Define::enum('users_default_user_role', ['user', 'artist', 'fan'], 'user')
            ->group($g)->subgroup('registration')
            ->label('Default role on signup')->auditCategory($cat)->register();

        Define::int('users_registration_limit_per_ip', 5)
            ->group($g)->subgroup('registration')
            ->rules(['integer', 'min:1', 'max:100'])
            ->label('Signups per IP per day')->auditCategory($cat)->register();

        Define::bool('users_verification_required_for_tickets', true)
            ->group($g)->subgroup('registration')
            ->label('Identity verification for ticket buyers')->auditCategory($cat)->register();

        Define::bool('users_verification_required_for_artists', false)
            ->group($g)->subgroup('registration')
            ->label('Identity verification for artists')->auditCategory($cat)->register();

        // Password policy
        Define::int('security_password_min_length', 8)
            ->group($g)->subgroup('password')
            ->rules(['integer', 'min:6', 'max:128'])
            ->label('Minimum password length')->auditCategory($cat)->register();

        foreach (['uppercase', 'lowercase', 'numbers', 'symbols'] as $cls) {
            Define::bool("security_password_require_{$cls}", $cls === 'numbers')
                ->group($g)->subgroup('password')
                ->label("Require {$cls}")->auditCategory($cat)->register();
        }

        Define::bool('security_allow_remember_me', true)
            ->group($g)->subgroup('session')
            ->label('Allow "remember me"')->auditCategory($cat)->register();
        Define::bool('security_enforce_single_session', false)
            ->group($g)->subgroup('session')
            ->label('Enforce single session per user')->auditCategory($cat)->register();

        // Session & lockout (canonical names match shadow keys used by middleware)
        Define::bool('security_two_factor_required', false)
            ->group($g)->subgroup('session')
            ->label('Require two-factor for admins')->auditCategory($cat)->register();

        Define::bool('security_enable_session_timeout', true)
            ->group($g)->subgroup('session')
            ->label('Auto-logout idle sessions')->auditCategory($cat)->register();

        Define::int('security_session_timeout_minutes', 120)
            ->group($g)->subgroup('session')
            ->rules(['integer', 'min:5', 'max:43200'])
            ->label('Session timeout (minutes)')->auditCategory($cat)->register();

        Define::int('auth_max_login_attempts', 5)
            ->group($g)->subgroup('session')
            ->rules(['integer', 'min:1', 'max:50'])
            ->label('Max failed login attempts')->auditCategory($cat)->register();

        Define::int('auth_lockout_duration', 15)
            ->group($g)->subgroup('session')
            ->rules(['integer', 'min:1', 'max:1440'])
            ->label('Lockout duration (minutes)')->auditCategory($cat)->register();

        // Security logging
        foreach ([
            'log_security_events' => 'Log security events',
            'log_failed_logins' => 'Log failed logins',
            'log_password_changes' => 'Log password changes',
        ] as $k => $label) {
            Define::bool("security_{$k}", true)
                ->group($g)->subgroup('logging')
                ->label($label)->auditCategory($cat)->register();
        }

        // Social login (canonical names = shadow keys consumers read)
        Define::bool('users_social_login_enabled', false)
            ->group($g)->subgroup('social_login')
            ->visibility(SettingVisibility::Public)
            ->label('Allow social login at all')->auditCategory($cat)->register();

        foreach (['google', 'facebook', 'apple'] as $provider) {
            Define::bool("auth_{$provider}_login_enabled", false)
                ->group($g)->subgroup('social_login')
                ->visibility(SettingVisibility::Public)
                ->label(ucfirst($provider).' login')
                ->auditCategory($cat)
                ->register();
        }

        // Deprecated duplicates
        Define::int('users_session_timeout_minutes', 120)
            ->group($g)->deprecatedInFavorOf('security_session_timeout_minutes')->register();
        Define::int('security_max_login_attempts', 5)
            ->group($g)->deprecatedInFavorOf('auth_max_login_attempts')->register();
        Define::int('security_lockout_duration_minutes', 15)
            ->group($g)->deprecatedInFavorOf('auth_lockout_duration')->register();
        foreach (['google', 'facebook', 'apple'] as $provider) {
            Define::bool("security_{$provider}_login_enabled", false)
                ->group($g)->deprecatedInFavorOf("auth_{$provider}_login_enabled")->register();
        }
    }
}
