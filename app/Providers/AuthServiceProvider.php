<?php

namespace App\Providers;

use App\Models\Artist;
use App\Models\Song;
use App\Models\Podcast;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Promotion;
use App\Policies\ArtistPolicy;
use App\Policies\SongPolicy;
use App\Policies\PodcastPolicy;
use App\Policies\Store\StorePolicy;
use App\Policies\Store\ProductPolicy;
use App\Policies\Store\OrderPolicy;
use App\Policies\Store\PromotionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Core models
        \App\Models\Artist::class => \App\Policies\ArtistPolicy::class,
        \App\Models\Song::class => \App\Policies\SongPolicy::class,
        \App\Models\Album::class => \App\Policies\AlbumPolicy::class,
        \App\Models\Playlist::class => \App\Policies\PlaylistPolicy::class,
        \App\Models\User::class => \App\Policies\UserPolicy::class,
        \App\Models\Payment::class => \App\Policies\PaymentPolicy::class,
        \App\Models\Podcast::class => \App\Policies\PodcastPolicy::class,
        
        // Store Module Policies
        \App\Modules\Store\Models\Store::class => \App\Policies\Store\StorePolicy::class,
        \App\Modules\Store\Models\Product::class => \App\Policies\Store\ProductPolicy::class,
        \App\Modules\Store\Models\Order::class => \App\Policies\Store\OrderPolicy::class,
        \App\Modules\Store\Models\Promotion::class => \App\Policies\Store\PromotionPolicy::class,
        
        // Forum Module Policies
        \App\Models\Modules\Forum\ForumTopic::class => \App\Policies\Modules\Forum\ForumTopicPolicy::class,
        \App\Models\Modules\Forum\ForumReply::class => \App\Policies\Modules\Forum\ForumReplyPolicy::class,
        \App\Models\Modules\Forum\Poll::class => \App\Policies\Modules\Forum\PollPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}