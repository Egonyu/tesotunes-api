<?php

namespace App\Providers;

use App\Settings\Definitions\AccessAuthSettings;
use App\Settings\Definitions\BrandingSettings;
use App\Settings\Definitions\CommerceSettings;
use App\Settings\Definitions\ContentRuleSettings;
use App\Settings\Definitions\FeatureSettings;
use App\Settings\Definitions\MobileVerificationSettings;
use App\Settings\Definitions\NotificationSettings;
use App\Settings\Definitions\PaymentSettings;
use App\Settings\Definitions\PlatformSettings;
use App\Settings\Definitions\SaccoSettings;
use App\Settings\Definitions\StorageSettings;
use App\Settings\SettingRegistry;
use App\Settings\SettingsManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    /** @var array<int, class-string> */
    private const DEFINITION_FILES = [
        PlatformSettings::class,
        FeatureSettings::class,
        BrandingSettings::class,
        AccessAuthSettings::class,
        ContentRuleSettings::class,
        CommerceSettings::class,
        PaymentSettings::class,
        NotificationSettings::class,
        MobileVerificationSettings::class,
        StorageSettings::class,
        SaccoSettings::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SettingRegistry::class);

        $this->app->singleton(SettingsManager::class, function (Container $app) {
            return new SettingsManager($app->make(SettingRegistry::class), $app);
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(SettingRegistry::class);

        foreach (self::DEFINITION_FILES as $class) {
            $class::register($registry);
        }
    }
}
