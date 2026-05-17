<?php

namespace App\Modules\Promotions\Providers;

use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Modules\Promotions\Policies\PromoterProfilePolicy;
use App\Modules\Promotions\Policies\PromotionOpportunityPolicy;
use App\Modules\Promotions\Services\OpportunityService;
use App\Modules\Promotions\Services\PromoterOnboardingService;
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
        $this->app->singleton(OpportunityService::class);
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
        Gate::policy(PromotionOpportunity::class, PromotionOpportunityPolicy::class);
    }

    protected function registerGates(): void
    {
        Gate::define('promotions.create-listing', function ($user) {
            return $user->promoterProfile()->exists();
        });

        Gate::define('promotions.post-opportunity', function ($user) {
            return true;
        });
    }
}
