<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: commerce — credits system, revenue split, package pricing.
 */
final class CommerceSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'commerce';
        $cat = 'commerce';

        Define::bool('credits_credits_enabled', true)
            ->group($g)->subgroup('credits')
            ->label('Credits system enabled')->auditCategory($cat)->register();

        Define::bool('credits_credit_purchase_enabled', true)
            ->group($g)->subgroup('credits')
            ->label('Allow credit purchases')->auditCategory($cat)->register();

        Define::int('credits_credit_to_ugx_rate', 100)
            ->group($g)->subgroup('credits')
            ->rules(['integer', 'min:1', 'max:1000000'])
            ->label('UGX per credit')->auditCategory($cat)->register();

        Define::int('credits_credits_per_song_upload', 5)
            ->group($g)->subgroup('credits')
            ->rules(['integer', 'min:0', 'max:10000'])
            ->label('Credits earned per upload')->auditCategory($cat)->register();

        Define::int('credits_credits_per_event_ticket', 10)
            ->group($g)->subgroup('credits')
            ->rules(['integer', 'min:0', 'max:10000'])
            ->label('Credits earned per ticket sold')->auditCategory($cat)->register();

        // Packages — canonical artist_revenue_share flat key (shadow wins reads)
        Define::float('artist_revenue_share', 70.0)
            ->group($g)->subgroup('revenue')
            ->rules(['numeric', 'min:0', 'max:100'])
            ->label('Artist revenue share %')
            ->help('Percent of net revenue paid to artists.')
            ->auditCategory($cat)->register();
        Define::float('payments_artist_revenue_share', 70.0)
            ->group($g)->deprecatedInFavorOf('artist_revenue_share')->register();

        // Package pricing
        foreach ([1, 2, 3] as $i) {
            Define::int("credits_package_{$i}_credits", [100, 500, 1000][$i - 1])
                ->group($g)->subgroup('packages')
                ->rules(['integer', 'min:1'])
                ->label("Package {$i} credits")->auditCategory($cat)->register();
            Define::int("credits_package_{$i}_price", [10000, 50000, 100000][$i - 1])
                ->group($g)->subgroup('packages')
                ->rules(['integer', 'min:1'])
                ->label("Package {$i} price (UGX)")->auditCategory($cat)->register();
            Define::bool("credits_package_{$i}_active", true)
                ->group($g)->subgroup('packages')
                ->label("Package {$i} active")->auditCategory($cat)->register();
        }
    }
}
