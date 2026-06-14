<?php

namespace App\Modules\Contributions\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * ContributionsServiceProvider — the Ateso corpus pipeline module.
 *
 * Follows the Store-module template: early-exits when the module is disabled
 * so it adds zero overhead until CONTRIBUTIONS_ENABLED is flipped on. Migrations
 * live in the canonical database/migrations path (one ground-up domain file),
 * so this provider only wires config and routes.
 */
class ContributionsServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = __DIR__.'/..';
    }

    public function register(): void
    {
        // Config is always merged so feature flags / tuning are readable even
        // when the module's HTTP surface is disabled.
        $this->mergeConfigFrom($this->modulePath.'/Config/contributions.php', 'contributions');
    }

    public function boot(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/contributions')
            ->name('contributions.api.')
            ->group($this->modulePath.'/Routes/api.php');
    }

    protected function isEnabled(): bool
    {
        return (bool) config('contributions.enabled', false);
    }
}
