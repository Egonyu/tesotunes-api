<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: payments — provider toggles and encrypted API keys.
 * Secrets are super-admin only and write-only on read.
 */
final class PaymentSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'payments';
        $cat = 'payments';

        foreach (['mtn', 'airtel', 'zengapay'] as $provider) {
            Define::bool("payments_{$provider}_enabled", false)
                ->group($g)->subgroup($provider)
                ->label(strtoupper($provider).' enabled')
                ->auditCategory($cat)->register();
        }

        Define::str('payments_zengapay_merchant_id')
            ->group($g)->subgroup('zengapay')
            ->label('ZengaPay merchant ID')
            ->auditCategory($cat)->register();

        foreach (['mtn_api_key', 'airtel_api_key', 'zengapay_api_key'] as $secret) {
            Define::secret("payments_{$secret}")
                ->group($g)->subgroup(explode('_', $secret)[0])
                ->label(strtoupper(explode('_', $secret)[0]).' API key')
                ->auditCategory($cat)->register();
        }

        Define::secret('payments_zengapay_webhook_secret')
            ->group($g)->subgroup('zengapay')
            ->label('ZengaPay webhook secret')
            ->auditCategory($cat)->register();

        // Payouts
        Define::bool('payments_payouts_enabled', true)
            ->group($g)->subgroup('payouts')
            ->label('Payouts enabled')->auditCategory($cat)->register();
        Define::int('payments_minimum_payout_ugx', 50000)
            ->group($g)->subgroup('payouts')
            ->rules(['integer', 'min:0'])
            ->label('Minimum payout (UGX)')->auditCategory($cat)->register();
        Define::int('payments_payout_hold_days', 7)
            ->group($g)->subgroup('payouts')
            ->rules(['integer', 'min:0', 'max:90'])
            ->label('Payout hold period (days)')->auditCategory($cat)->register();
        Define::enum('payments_payout_schedule', ['daily', 'weekly', 'biweekly', 'monthly'], 'weekly')
            ->group($g)->subgroup('payouts')
            ->label('Payout schedule')->auditCategory($cat)->register();
        Define::float('payments_transaction_fee_percentage', 2.5)
            ->group($g)->subgroup('payouts')
            ->rules(['numeric', 'min:0', 'max:100'])
            ->label('Transaction fee %')->auditCategory($cat)->register();
    }
}
