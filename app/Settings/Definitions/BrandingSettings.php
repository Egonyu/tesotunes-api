<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingRegistry;

/**
 * Group: branding — logos, colors, login experience, admin identity.
 * Maps to legacy "appearance" section. Public-visible because the login
 * page and unauthenticated surfaces need these.
 */
final class BrandingSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'branding';
        $cat = 'branding';

        // Brand colors / logos
        Define::str('appearance_primary_color', '#10B981')
            ->group($g)->subgroup('brand')
            ->rules(['required', 'regex:/^#[0-9A-Fa-f]{6}$/'])
            ->visibility(SettingVisibility::Public)
            ->label('Primary color')->auditCategory($cat)->register();

        Define::str('appearance_app_name', 'TesoTunes')
            ->group($g)->subgroup('brand')
            ->visibility(SettingVisibility::Public)
            ->label('Brand display name')->auditCategory($cat)->register();

        foreach (['logo_light', 'logo_dark', 'favicon'] as $f) {
            Define::image("appearance_{$f}")
                ->group($g)->subgroup('brand')
                ->visibility(SettingVisibility::Public)
                ->label(ucwords(str_replace('_', ' ', $f)))
                ->auditCategory($cat)->register();
        }

        foreach (['logo_alt', 'logo_compact_label'] as $f) {
            Define::str("appearance_{$f}")
                ->group($g)->subgroup('brand')
                ->visibility(SettingVisibility::Public)
                ->label(ucwords(str_replace('_', ' ', $f)))
                ->auditCategory($cat)->register();
        }

        // Admin panel identity
        Define::str('appearance_admin_panel_name', 'Admin Panel')
            ->group($g)->subgroup('admin_identity')
            ->label('Admin panel name')->auditCategory($cat)->register();
        Define::str('appearance_admin_panel_subtitle', 'Platform operations')
            ->group($g)->subgroup('admin_identity')
            ->label('Admin panel subtitle')->auditCategory($cat)->register();

        // Login experience (all public — rendered on unauthenticated page)
        $loginStrings = [
            'auth_form_title' => 'Welcome back',
            'auth_form_subtitle' => 'Sign in to continue listening to your favorite music',
            'auth_hero_title' => 'Discover East African Music',
            'auth_hero_description' => 'Stream millions of songs, discover new artists, and support the sounds of East Africa.',
            'auth_hero_image' => '',
            'auth_stat_1_value' => '10K+', 'auth_stat_1_label' => 'Songs',
            'auth_stat_2_value' => '500+', 'auth_stat_2_label' => 'Artists',
            'auth_stat_3_value' => '50K+', 'auth_stat_3_label' => 'Users',
        ];
        foreach ($loginStrings as $field => $default) {
            Define::str("appearance_{$field}", $default)
                ->group($g)->subgroup('login_experience')
                ->visibility(SettingVisibility::Public)
                ->label(ucwords(str_replace('_', ' ', $field)))
                ->auditCategory($cat)->register();
        }

        // Homepage layout
        Define::enum('appearance_homepage_theme', ['classic_home', 'curated_home'], 'classic_home')
            ->group($g)->subgroup('layout')
            ->visibility(SettingVisibility::Public)
            ->label('Homepage theme')->auditCategory($cat)->register();

        // Deprecated: SACCO branding strings duplicated in sacco_settings table.
        Define::str('appearance_sacco_name', 'TesoTunes SACCO')
            ->group($g)->deprecatedInFavorOf('sacco_sacco_name')->register();
        Define::str('appearance_sacco_tagline', 'Artist Finance Platform')
            ->group($g)->deprecatedInFavorOf('sacco_sacco_tagline')->register();
    }
}
