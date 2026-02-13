<?php

namespace App\Modules\Sacco\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

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
        $this->modulePath = __DIR__ . '/..';
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        // Only boot if module is enabled
        if (!$this->isEnabled()) {
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
            $this->modulePath . '/Config/sacco.php',
            'sacco'
        );

        // Register services only if enabled
        if ($this->isEnabled()) {
            $this->registerServices();
        }
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
            $this->modulePath . '/Config/sacco.php' => config_path('sacco.php'),
        ], 'sacco-config');
    }

    /**
     * Register migrations
     */
    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom($this->modulePath . '/Database/Migrations');
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
     * Register services
     */
    protected function registerServices(): void
    {
        $this->app->singleton('sacco.credit-score', function ($app) {
            return new \App\Modules\Sacco\Services\SaccoCreditScoreService();
        });

        $this->app->singleton('sacco.interest', function ($app) {
            return new \App\Modules\Sacco\Services\SaccoInterestService();
        });

        $this->app->singleton('sacco.loan', function ($app) {
            return new \App\Modules\Sacco\Services\SaccoLoanService(
                $app->make('sacco.interest')
            );
        });

        $this->app->singleton('sacco.mobile-money', function ($app) {
            return new \App\Modules\Sacco\Services\SaccoMobileMoneyService();
        });
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
            $this->modulePath . '/Resources/assets' => public_path('modules/sacco'),
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
