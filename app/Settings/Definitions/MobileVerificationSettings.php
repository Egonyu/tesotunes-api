<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: mobile — SMS verification policy and provider selection.
 */
final class MobileVerificationSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'mobile';
        $cat = 'mobile_verification';

        Define::bool('mobile_mobile_verification_enabled', true)
            ->group($g)->subgroup('policy')
            ->label('Mobile verification enabled')->auditCategory($cat)->register();
        Define::bool('mobile_mobile_verification_required_for_events', false)
            ->group($g)->subgroup('policy')
            ->label('Required for event hosts')->auditCategory($cat)->register();
        Define::bool('mobile_mobile_verification_required_for_artists', false)
            ->group($g)->subgroup('policy')
            ->label('Required for artists')->auditCategory($cat)->register();

        Define::enum('mobile_sms_provider', ['local', 'twilio', 'africastalking', 'termii'], 'local')
            ->group($g)->subgroup('provider')
            ->label('SMS provider')->auditCategory($cat)->register();

        // Additional policy toggles surfaced by frontend writes
        foreach (['signup', 'login', 'payouts'] as $surface) {
            Define::bool("mobile_mobile_verification_required_for_{$surface}", false)
                ->group($g)->subgroup('policy')
                ->label("Required for {$surface}")
                ->auditCategory($cat)->register();
        }

        // Code & throttle
        Define::int('mobile_verification_code_length', 6)
            ->group($g)->subgroup('codes')
            ->rules(['integer', 'min:4', 'max:10'])
            ->label('Verification code length')->auditCategory($cat)->register();
        Define::int('mobile_verification_expiry_minutes', 5)
            ->group($g)->subgroup('codes')
            ->rules(['integer', 'min:1', 'max:60'])
            ->label('Code expiry (minutes)')->auditCategory($cat)->register();
        Define::int('mobile_max_verification_attempts', 5)
            ->group($g)->subgroup('codes')
            ->rules(['integer', 'min:1', 'max:50'])
            ->label('Max verification attempts')->auditCategory($cat)->register();
        Define::int('mobile_resend_cooldown_seconds', 60)
            ->group($g)->subgroup('codes')
            ->rules(['integer', 'min:0', 'max:3600'])
            ->label('Resend cooldown (seconds)')->auditCategory($cat)->register();
    }
}
