<?php

namespace App\Providers;

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
        // Audit logging is bootstrapped via event listeners in AppServiceProvider.
        // This provider exists for future audit-specific bindings
        // (e.g., custom audit log drivers, retention policies).
    }
}
