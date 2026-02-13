<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        View::composer('*', 'App\View\Composers\SettingsComposer');

        // Admin sidebar composer for backend navigation
        View::composer(
            'components.backend.partials.main-sidebar',
            \App\Http\View\Composers\AdminSidebarComposer::class
        );
        
        // Right sidebar composer for polls and trending content
        View::composer(
            'frontend.partials.modern-right-sidebar',
            \App\Http\View\Composers\RightSidebarComposer::class
        );
    }
}
