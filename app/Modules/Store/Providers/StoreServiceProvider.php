<?php

namespace App\Modules\Store\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;

/**
 * StoreServiceProvider
 * 
 * Main service provider for the Store module
 * Handles module registration, routing, and feature loading
 * ONLY loads when STORE_ENABLED=true
 */
class StoreServiceProvider extends ServiceProvider
{
    protected string $moduleNamespace = 'App\Modules\Store\Http\Controllers';
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = __DIR__ . '/..';
    }

    /**
     * Register services
     */
    public function register(): void
    {
        // Early exit if module is disabled - NO OVERHEAD
        if (!$this->isEnabled()) {
            return;
        }

        // Merge module configuration
        $this->mergeConfigFrom(
            $this->modulePath . '/Config/store.php',
            'store'
        );

        // Register services as singletons
        $this->registerServices();
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Early exit if module is disabled - NO OVERHEAD
        if (!$this->isEnabled()) {
            return;
        }

        // Ensure storage disk is valid and fallback to public if needed
        $this->ensureValidStorageDisk();

        // Load migrations
        $this->loadMigrationsFrom($this->modulePath . '/Database/Migrations');

        // Register routes
        $this->registerRoutes();

        // Register middleware
        $this->registerMiddleware();

        // Register policies (when we create them)
        $this->registerPolicies();

        // Publish configuration
        $this->publishes([
            $this->modulePath . '/Config/store.php' => config_path('store.php'),
        ], 'store-config');
    }

    /**
     * Ensure storage disk is valid and fallback to public if cloud storage isn't configured
     */
    protected function ensureValidStorageDisk(): void
    {
        $disk = config('store.storage.disk');
        
        // If using cloud storage (digitalocean or s3), check if it's properly configured
        if (in_array($disk, ['digitalocean', 's3'])) {
            $diskConfig = config("filesystems.disks.{$disk}", []);
            
            // Check if required credentials are missing
            $isConfigured = !empty($diskConfig['key']) && 
                           !empty($diskConfig['secret']) && 
                           !empty($diskConfig['bucket']);
            
            if (!$isConfigured) {
                // Fallback to public disk
                config(['store.storage.disk' => 'public']);
                \Log::info("Store module: Cloud storage '{$disk}' not configured, falling back to 'public' disk");
            }
        }
    }

    /**
     * Check if Store module is enabled
     */
    protected function isEnabled(): bool
    {
        return config('store.enabled', false);
    }

    /**
     * Register module services
     */
    protected function registerServices(): void
    {
        // Store management service
        $this->app->singleton('store.management', function ($app) {
            return new \App\Modules\Store\Services\StoreService();
        });

        // Product management service
        $this->app->singleton('store.products', function ($app) {
            return new \App\Modules\Store\Services\ProductService();
        });

        // Order management service  
        $this->app->singleton('store.orders', function ($app) {
            return new \App\Modules\Store\Services\OrderService();
        });

        // Payment service
        $this->app->singleton('store.payments', function ($app) {
            return new \App\Modules\Store\Services\PaymentService();
        });
    }

    /**
     * Register routes
     */
    protected function registerRoutes(): void
    {
        // Seller routes (store management)
        Route::middleware(['web', 'auth', 'store.enabled'])
            ->prefix('store/seller')
            ->name('store.seller.')
            ->namespace($this->moduleNamespace . '\Seller')
            ->group($this->modulePath . '/Routes/seller.php');

        // Buyer routes (shopping)
        Route::middleware(['web', 'store.enabled'])
            ->prefix('store/shop')
            ->name('store.shop.')
            ->namespace($this->moduleNamespace . '\Buyer')
            ->group($this->modulePath . '/Routes/buyer.php');

        // API routes
        Route::middleware(['api', 'store.enabled'])
            ->prefix('api/store')
            ->name('store.api.')
            ->namespace($this->moduleNamespace . '\Api')
            ->group($this->modulePath . '/Routes/api.php');
    }

    /**
     * Register middleware
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        
        $router->aliasMiddleware('store.enabled', \App\Modules\Store\Http\Middleware\StoreEnabled::class);
        $router->aliasMiddleware('store.owner', \App\Modules\Store\Http\Middleware\StoreOwner::class);
    }

    /**
     * Register policies
     */
    protected function registerPolicies(): void
    {
        Gate::policy(\App\Modules\Store\Models\Store::class, \App\Modules\Store\Policies\StorePolicy::class);
        Gate::policy(\App\Modules\Store\Models\Product::class, \App\Modules\Store\Policies\ProductPolicy::class);
        Gate::policy(\App\Modules\Store\Models\Order::class, \App\Modules\Store\Policies\OrderPolicy::class);
        Gate::policy(\App\Modules\Store\Models\ShoppingCart::class, \App\Modules\Store\Policies\CartPolicy::class);
    }

    /**
     * Get the services provided by the provider
     */
    public function provides(): array
    {
        return [
            'store.management',
            'store.products',
            'store.orders',
            'store.payments',
        ];
    }
}
