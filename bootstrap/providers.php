<?php

return [
    App\Modules\Store\Providers\StoreServiceProvider::class,
    App\Modules\Ojokotau\Providers\OjokotauServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\AuditLoggingServiceProvider::class,
    App\Providers\PodcastServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
    App\Providers\ViewServiceProvider::class,
];
