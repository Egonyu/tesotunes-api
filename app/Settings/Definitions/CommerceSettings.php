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

        /*
         * The credit/UGX rate.
         *
         * `credits_per_ugx` is the one the platform actually honours:
         * CreditController::getCreditsPerUgx() and Payment::creditsFor() read
         * it, and it was never registered here — so it fell through to the
         * config default of 1, meaning one credit is one shilling.
         *
         * `credits_credit_to_ugx_rate` was registered, shown on the admin
         * settings screen, defaulted to 100, and read by nothing. An operator
         * looking at that screen saw a rate a hundred times the real one. It
         * is the inverse concept too — "UGX per credit" against "credits per
         * UGX" — which is how the two came to disagree without anyone
         * noticing. It is now deprecated in favour of the live key.
         */
        Define::int('credits_per_ugx', 1)
            ->group($g)->subgroup('credits')
            ->rules(['integer', 'min:1', 'max:1000000'])
            ->label('Credits per UGX')
            ->help('How many credits one shilling buys. Also the redemption rate: credits convert back to the wallet at the same ratio.')
            ->auditCategory($cat)->register();

        Define::int('credits_credit_to_ugx_rate', 1)
            ->group($g)->subgroup('credits')
            ->deprecatedInFavorOf('credits_per_ugx')->register();

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

        /*
         * Package pricing.
         *
         * The tiers used to read 100 credits for 10,000 UGX — a hundred
         * shillings a credit, while the exchange redeems at one. Nothing
         * consumes these settings today, so nobody was ever charged that,
         * but they sat on an admin form looking live, which is how a figure
         * like that eventually gets acted on.
         *
         * The UGX price points are kept; the credit amounts now match them
         * at the platform's actual 1:1 rate.
         */
        foreach ([1, 2, 3] as $i) {
            Define::int("credits_package_{$i}_credits", [10000, 50000, 100000][$i - 1])
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
