<?php

return array_filter([
    App\Modules\Ojokotau\Providers\OjokotauServiceProvider::class,
    App\Modules\Sacco\Providers\SaccoServiceProvider::class,
    App\Modules\Promotions\Providers\PromotionsServiceProvider::class,
    App\Modules\Store\Providers\StoreServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\AuditLoggingServiceProvider::class,
    App\Providers\PodcastServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
    class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)
        ? App\Providers\TelescopeServiceProvider::class
        : null,
    App\Providers\ViewServiceProvider::class,
]);
