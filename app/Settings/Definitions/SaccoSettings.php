<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\Enums\SettingStore;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingRegistry;

/**
 * Group: sacco — SACCO finance configuration.
 * Backed by the legacy sacco_settings table via SaccoTableStoreDriver.
 */
final class SaccoSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'sacco';
        $cat = 'sacco';
        $store = SettingStore::SaccoTable;

        Define::str('sacco_sacco_name', 'TesoTunes SACCO')
            ->group($g)->subgroup('identity')->store($store)
            ->visibility(SettingVisibility::Public)
            ->label('SACCO name')->auditCategory($cat)->register();
        Define::str('sacco_sacco_tagline', 'Artist Finance Platform')
            ->group($g)->subgroup('identity')->store($store)
            ->visibility(SettingVisibility::Public)
            ->label('SACCO tagline')->auditCategory($cat)->register();

        $money = [
            'share_price_ugx' => [50000, 'Share price'],
            'minimum_savings_balance_ugx' => [50000, 'Minimum savings balance'],
            'default_join_deposit_ugx' => [50000, 'Default join deposit'],
            'monthly_savings_target_ugx' => [500000, 'Monthly savings target'],
        ];
        foreach ($money as $k => [$default, $label]) {
            Define::int("sacco_{$k}", $default)
                ->group($g)->subgroup('finance')->store($store)
                ->rules(['integer', 'min:0'])
                ->label("{$label} (UGX)")->auditCategory($cat)->register();
        }

        Define::int('sacco_default_join_shares', 5)
            ->group($g)->subgroup('finance')->store($store)
            ->rules(['integer', 'min:1'])
            ->label('Default join shares')->auditCategory($cat)->register();
        Define::int('sacco_minimum_initial_shares', 5)
            ->group($g)->subgroup('finance')->store($store)
            ->rules(['integer', 'min:1'])
            ->label('Minimum initial shares')->auditCategory($cat)->register();

        Define::float('sacco_annual_interest_rate', 12.0)
            ->group($g)->subgroup('rates')->store($store)
            ->rules(['numeric', 'min:0', 'max:100'])
            ->label('Annual interest rate %')->auditCategory($cat)->register();
        Define::float('sacco_annual_dividend_rate', 8.0)
            ->group($g)->subgroup('rates')->store($store)
            ->rules(['numeric', 'min:0', 'max:100'])
            ->label('Annual dividend rate %')->auditCategory($cat)->register();
        Define::float('sacco_max_loan_multiplier', 3.0)
            ->group($g)->subgroup('rates')->store($store)
            ->rules(['numeric', 'min:0.1', 'max:20'])
            ->label('Max loan multiplier')->auditCategory($cat)->register();

        $copy = [
            'guest_title' => 'Join Our Artist SACCO',
            'guest_description' => 'A savings and credit cooperative designed exclusively for music artists. Save together, grow together.',
            'member_title' => 'Welcome Back, Member!',
            'member_description' => 'Manage your savings, shares, and loans. Build your financial future with fellow artists.',
            'cta_title' => 'Ready to Join?',
            'cta_description' => 'Becoming a member is easy. Start with a minimum of UGX 50,000 and begin your journey to financial growth with fellow artists.',
        ];
        foreach ($copy as $k => $default) {
            Define::str("sacco_{$k}", $default)
                ->group($g)->subgroup('copy')->store($store)
                ->visibility(SettingVisibility::Public)
                ->label(ucwords(str_replace('_', ' ', $k)))
                ->auditCategory($cat)->register();
        }
    }
}
