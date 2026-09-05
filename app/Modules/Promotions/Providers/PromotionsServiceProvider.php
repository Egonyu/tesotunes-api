<?php

namespace App\Modules\Promotions\Providers;

use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Promotions\Policies\PromoterProfilePolicy;
use App\Modules\Promotions\Policies\PromotionRequestPolicy;
use App\Modules\Promotions\Services\PromoterOnboardingService;
use App\Modules\Promotions\Services\PromotionRequestService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PromotionsServiceProvider extends ServiceProvider
{
    protected string $modulePath;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->modulePath = __DIR__.'/..';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('config/promotions.php'),
            'promotions'
        );

        $this->app->singleton(PromoterOnboardingService::class);
        $this->app->singleton(PromotionRequestService::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPolicies();
        $this->registerGates();
    }

    protected function registerRoutes(): void
    {
        Route::middleware(['api'])
            ->prefix('api')
            ->group($this->modulePath.'/Routes/api.php');
    }

    protected function registerPolicies(): void
    {
        Gate::policy(PromoterProfile::class, PromoterProfilePolicy::class);
        Gate::policy(PromotionRequest::class, PromotionRequestPolicy::class);
    }

    protected function registerGates(): void
    {
        Gate::define('promotions.create-listing', function ($user) {
            return $user->promoterProfile()->exists();
        });

        Gate::define('promotions.post-request', function ($user) {
            return true;
        });
    }
}
