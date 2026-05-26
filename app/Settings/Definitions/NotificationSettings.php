<?php

namespace App\Settings\Definitions;

use App\Settings\Define;
use App\Settings\SettingRegistry;

/**
 * Group: notifications — channels and email/SMTP defaults.
 * Note: SMTP host/port stored here are decorative — Laravel reads .env.
 * They're kept for the admin panel UX but flagged requiresRestart.
 */
final class NotificationSettings
{
    public static function register(SettingRegistry $registry): void
    {
        $g = 'notifications';
        $cat = 'notifications';

        Define::bool('notifications_push_enabled', true)
            ->group($g)->subgroup('channels')
            ->label('Push notifications')->auditCategory($cat)->register();
        Define::bool('notifications_email_enabled', true)
            ->group($g)->subgroup('channels')
            ->label('Email notifications')->auditCategory($cat)->register();
        Define::bool('notifications_sms_enabled', false)
            ->group($g)->subgroup('channels')
            ->label('SMS notifications')->auditCategory($cat)->register();

        Define::enum('notifications_digest_frequency', ['daily', 'weekly', 'never'], 'daily')
            ->group($g)->subgroup('channels')
            ->label('Digest frequency')->auditCategory($cat)->register();

        // Per-event admin notifications
        $events = [
            'new_registrations' => 'New registrations',
            'new_uploads' => 'New uploads',
            'payout_requests' => 'Payout requests',
            'content_reports' => 'Content reports',
            'new_orders' => 'New orders',
            'failed_payments' => 'Failed payments',
        ];
        foreach ($events as $k => $label) {
            Define::bool("notifications_notify_{$k}", true)
                ->group($g)->subgroup('events')
                ->label("Notify on {$label}")
                ->auditCategory($cat)->register();
        }

        // SMTP — flagged as decorative until wired to .env via Env driver.
        Define::str('email_smtp_host')
            ->group($g)->subgroup('email')
            ->requiresRestart()
            ->label('SMTP host')->auditCategory($cat)->register();
        Define::int('email_smtp_port', 587)
            ->group($g)->subgroup('email')
            ->rules(['integer', 'min:1', 'max:65535'])
            ->requiresRestart()
            ->label('SMTP port')->auditCategory($cat)->register();
        Define::str('email_smtp_username')
            ->group($g)->subgroup('email')
            ->requiresRestart()
            ->label('SMTP username')->auditCategory($cat)->register();
        Define::str('email_smtp_from_name', 'TesoTunes')
            ->group($g)->subgroup('email')
            ->requiresRestart()
            ->label('From name')->auditCategory($cat)->register();
        Define::email('email_smtp_from_email', 'noreply@tesotunes.com')
            ->group($g)->subgroup('email')
            ->requiresRestart()
            ->label('From email')->auditCategory($cat)->register();
    }
}
