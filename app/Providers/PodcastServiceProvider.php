<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;

class PodcastServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Early exit if module is disabled
        if (!config('podcast.enabled', false)) {
            return;
        }

        // Register service bindings
        $this->app->singleton(\App\Services\Podcast\PodcastService::class);
        $this->app->singleton(\App\Services\Podcast\EpisodeService::class);
        $this->app->singleton(\App\Services\Podcast\RssFeedService::class);
        $this->app->singleton(\App\Services\Podcast\AnalyticsService::class);
        
        // Register transcription service if enabled
        if (config('podcast.processing.transcription.enabled')) {
            $this->app->singleton(\App\Services\Podcast\TranscriptionService::class);
        }

        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/podcast.php',
            'podcast'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Early exit if module is disabled
        if (!config('podcast.enabled', false)) {
            return;
        }

        // Load routes only if route files exist
        if ($this->routeFilesExist()) {
            $this->registerRoutes();
        }
        
        // Load migrations only if directory exists
        $migrationsPath = __DIR__ . '/../../database/migrations/podcast';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
        
        // Load views only if directory exists
        $viewsPath = __DIR__ . '/../../resources/views/podcast';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'podcast');
        }
        
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/podcast.php' => config_path('podcast.php'),
        ], 'podcast-config');
        
        // Publish migrations only if source exists
        if (is_dir($migrationsPath)) {
            $this->publishes([
                $migrationsPath => database_path('migrations'),
            ], 'podcast-migrations');
        }
        
        // Register Blade components
        $this->registerBladeComponents();
        
        // Register policies
        $this->registerPolicies();
    }

    /**
     * Check if required route files exist.
     */
    protected function routeFilesExist(): bool
    {
        return file_exists(base_path('routes/podcast.php')) && 
               file_exists(base_path('routes/podcast-api.php'));
    }

    /**
     * Register routes based on module configuration.
     */
    protected function registerRoutes(): void
    {
        // Frontend routes
        if (file_exists(base_path('routes/podcast.php'))) {
            Route::middleware('web')
                ->prefix('podcasts')
                ->name('podcast.')
                ->group(base_path('routes/podcast.php'));
        }
        
        // API routes
        if (file_exists(base_path('routes/podcast-api.php'))) {
            Route::middleware('api')
                ->prefix('api/podcasts')
                ->name('api.podcast.')
                ->group(base_path('routes/podcast-api.php'));
        }
        
        // Admin routes - now handled in routes/admin/podcasts.php
        // Route::middleware(['web', 'auth', 'role:admin,super_admin'])
        //     ->prefix('admin/podcasts')
        //     ->name('admin.podcast.')
        //     ->group(base_path('routes/podcast-admin.php'));
    }

    /**
     * Register Blade components for podcast UI.
     */
    protected function registerBladeComponents(): void
    {
        Blade::componentNamespace('App\\View\\Components\\Podcast', 'podcast');
        
        // Register individual components when they're created
        // Blade::component('podcast::components.player', 'podcast-player');
        // Blade::component('podcast::components.episode-card', 'podcast-episode-card');
        // Blade::component('podcast::components.podcast-card', 'podcast-card');
    }

    /**
     * Register authorization policies.
     */
    protected function registerPolicies(): void
    {
        // Register policies when models are created
        // Gate::policy(\App\Models\Podcast::class, \App\Policies\PodcastPolicy::class);
        // Gate::policy(\App\Models\PodcastEpisode::class, \App\Policies\PodcastEpisodePolicy::class);
    }
}
