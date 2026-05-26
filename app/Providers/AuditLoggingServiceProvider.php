<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Observers\AuditLogObserver;
use Illuminate\Support\ServiceProvider;

/**
 * AuditLoggingServiceProvider
 *
 * Registers audit logging services for tracking user actions,
 * authentication events, and admin activities.
 */
class AuditLoggingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Mirror every AuditLog row into the security console as an
        // ObservabilityEvent via AuditLogObserver. Audit logs are the
        // chokepoint for payments, webhooks and privilege changes, so
        // observing them gives the integrity + payments domains real-time
        // coverage without instrumenting individual callers.
        AuditLog::observe(AuditLogObserver::class);
    }
}
