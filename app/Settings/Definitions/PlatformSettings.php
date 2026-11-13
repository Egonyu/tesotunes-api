<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingRegistry;

/**
 * Group: platform — identity, localization, contact, top-level operations.
 * Maps to legacy "general" section, minus the duplicated feature/user toggles
 * (those moved to FeatureSettings and AccessAuthSettings respectively).
 */
final class PlatformSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'platform';

        // Identity
        Define::str('general_platform_name', 'TesoTunes')
            ->group($g)->subgroup('identity')
            ->rules(['required', 'string', 'max:120'])
            ->visibility(SettingVisibility::Public)
            ->label('Platform name')->auditCategory('platform_identity')->register();

        Define::str('general_edula_towns', 'Soroti,Kumi,Katakwi,Ngora,Bukedea,Amuria,Serere,Kaberamaido,Kapelebyong,Kalaki,Kampala,Diaspora')
            ->group($g)->subgroup('identity')
            ->visibility(SettingVisibility::Public)
            ->label('Edula towns')
            ->help('Comma-separated places a member can choose as home town; deals and events from it rank first.')
            ->auditCategory('platform_identity')->register();

        Define::int('general_edula_voice_reply_seconds', 60)
            ->group($g)->subgroup('identity')
            ->visibility(SettingVisibility::Public)
            ->rules(['integer', 'min:0', 'max:300'])
            ->label('Edula voice reply length (seconds)')
            ->help('Longest voice reply a member can record on a post. 0 turns voice replies off.')
            ->auditCategory('platform_identity')->register();

        // Ateso wording should be confirmed by native speakers; operators can edit it.
        Define::str('general_edula_reactions', 'ejok:Ejok!,ejok_noi:Ejok noi,eyalama:Eyalama')
            ->group($g)->subgroup('identity')
            ->visibility(SettingVisibility::Public)
            ->rules(['nullable', 'string', 'max:500'])
            ->label('Edula reactions')
            ->help('Comma-separated key:Label pairs offered as one-tap reactions on Edula. Keys are lowercase; removing one stops it being counted.')
            ->auditCategory('platform_identity')->register();

        Define::str('general_tagline', 'Empowering Artists, Connecting Fans')
            ->group($g)->subgroup('identity')
            ->visibility(SettingVisibility::Public)
            ->label('Tagline')->auditCategory('platform_identity')->register();

        Define::str('general_platform_description')
            ->group($g)->subgroup('identity')
            ->label('Platform description')->auditCategory('platform_identity')->register();

        Define::url('general_platform_url')
            ->group($g)->subgroup('identity')
            ->visibility(SettingVisibility::Public)
            ->label('Platform URL')->auditCategory('platform_identity')->register();

        // Contact
        Define::email('general_support_email', 'support@tesotunes.com')
            ->group($g)->subgroup('contact')
            ->rules(['required', 'email:rfc'])
            ->label('Support email')->auditCategory('platform_identity')->register();

        Define::email('general_admin_contact', 'support@tesotunes.com')
            ->group($g)->subgroup('contact')
            ->label('Admin contact')->auditCategory('platform_identity')->register();

        // Localization
        Define::enum('general_default_language', ['en', 'sw', 'fr'], 'en')
            ->group($g)->subgroup('localization')
            ->visibility(SettingVisibility::Public)
            ->label('Default language')->auditCategory('platform_identity')->register();

        Define::enum('general_default_currency', ['UGX', 'KES', 'TZS', 'RWF', 'USD'], 'UGX')
            ->group($g)->subgroup('localization')
            ->visibility(SettingVisibility::Public)
            ->label('Default currency')->auditCategory('platform_identity')->register();

        Define::str('general_timezone', 'Africa/Kampala')
            ->group($g)->subgroup('localization')
            ->rules(['required', 'timezone'])
            ->label('Default timezone')->auditCategory('platform_identity')->register();

        // Operations
        Define::bool('general_maintenance_mode', false)
            ->group($g)->subgroup('operations')
            ->label('Maintenance mode')
            ->help('Blocks all non-admin traffic.')
            ->auditCategory('platform_operations')->register();

        // Deprecated duplicates — canonical homes live in other groups.
        Define::bool('general_registration_enabled', true)
            ->group($g)->deprecatedInFavorOf('users_user_registration_enabled')->register();

        Define::bool('general_email_verification_required', true)
            ->group($g)->deprecatedInFavorOf('users_email_verification_required')->register();

        Define::bool('general_artist_approval_required', false)
            ->group($g)->deprecatedInFavorOf('users_artist_approval_required')->register();

        Define::bool('general_social_login_enabled', false)
            ->group($g)->deprecatedInFavorOf('users_social_login_enabled')->register();

        Define::bool('general_phone_verification_enabled', true)
            ->group($g)->deprecatedInFavorOf('users_phone_verification_enabled')->register();

        Define::str('general_default_user_role', 'user')
            ->group($g)->deprecatedInFavorOf('users_default_user_role')->register();

        Define::int('general_registration_limit_per_ip', 5)
            ->group($g)->deprecatedInFavorOf('users_registration_limit_per_ip')->register();

        Define::bool('general_verification_required_for_tickets', true)
            ->group($g)->deprecatedInFavorOf('users_verification_required_for_tickets')->register();

        Define::bool('general_verification_required_for_artists', false)
            ->group($g)->deprecatedInFavorOf('users_verification_required_for_artists')->register();

        Define::bool('general_credits_enabled', true)
            ->group($g)->deprecatedInFavorOf('credits_credits_enabled')->register();
    }
}
