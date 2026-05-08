<?php

namespace App\Modules\Sacco\Providers;

use Illuminate\Support\ServiceProvider;

class SaccoServiceProvider extends ServiceProvider
{
    /**
     * @var string Module namespace
     */
    protected string $moduleNamespace = 'App\Modules\Sacco\Http\Controllers';

    /**
     * @var string Module path
     */
    protected string $modulePath;

    /**
     * Constructor
     */
    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = __DIR__.'/..';
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        // Only boot if module is enabled
        if (! $this->isEnabled()) {
            return;
        }

        $this->registerConfig();
        $this->registerMigrations();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->publishAssets();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Register config
        $this->mergeConfigFrom(
            $this->modulePath.'/Config/sacco.php',
            'sacco'
        );

        // Canonical SACCO services live in app/Services/Sacco/ and are resolved
        // via normal Laravel auto-resolution — no explicit bindings required.
    }

    /**
     * Check if module is enabled
     */
    protected function isEnabled(): bool
    {
        return config('sacco.enabled', false);
    }

    /**
     * Register config
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            $this->modulePath.'/Config/sacco.php' => config_path('sacco.php'),
        ], 'sacco-config');
    }

    /**
     * Register migrations
     */
    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom($this->modulePath.'/Database/Migrations');
    }

    /**
     * Register middleware
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('sacco.enabled', \App\Modules\Sacco\Http\Middleware\SaccoEnabled::class);
    }

    /**
     * Register commands
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Sacco\Console\Commands\CalculateDailyInterest::class,
                \App\Modules\Sacco\Console\Commands\CheckOverdueLoans::class,
                \App\Modules\Sacco\Console\Commands\UpdateCreditScores::class,
            ]);
        }
    }

    /**
     * Publish assets
     */
    protected function publishAssets(): void
    {
        $this->publishes([
            $this->modulePath.'/Resources/assets' => public_path('modules/sacco'),
        ], 'sacco-assets');
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
