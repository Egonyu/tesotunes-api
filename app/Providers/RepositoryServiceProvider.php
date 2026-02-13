<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\SongRepositoryInterface;
use App\Repositories\Contracts\ArtistRepositoryInterface;
use App\Repositories\Contracts\AlbumRepositoryInterface;
use App\Repositories\SongRepository;
use App\Repositories\ArtistRepository;
use App\Repositories\AlbumRepository;

/**
 * Repository Service Provider
 * 
 * Binds repository interfaces to their implementations
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Bind Song Repository
        $this->app->bind(SongRepositoryInterface::class, SongRepository::class);

        // Bind Artist Repository
        $this->app->bind(ArtistRepositoryInterface::class, ArtistRepository::class);

        // Bind Album Repository
        $this->app->bind(AlbumRepositoryInterface::class, AlbumRepository::class);
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        //
    }
}
