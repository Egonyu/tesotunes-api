<?php

namespace App\Modules\Ojokotau\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * OjokotauServiceProvider
 *
 * Service provider for the Ojokotau (crowdfunding/campaigns) module.
 * ONLY loads when OJOKOTAU_ENABLED=true in environment.
 */
class OjokotauServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Module-specific bindings can be added here
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (! config('modules.ojokotau.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
