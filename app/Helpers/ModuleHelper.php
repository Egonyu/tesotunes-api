<?php

use App\Models\ModuleSetting;
use App\Helpers\CacheHelper;

if (!function_exists('moduleEnabled')) {
    /**
     * Check if a module is enabled
     *
     * @param string $moduleName
     * @return bool
     */
    function moduleEnabled(string $moduleName): bool
    {
        return CacheHelper::remember(
            ['modules'],
            "module:enabled:{$moduleName}",
            3600,
            fn() => ModuleSetting::where('module_name', $moduleName)
                        ->where('is_enabled', true)
                        ->exists()
        );
    }
}

if (!function_exists('moduleConfig')) {
    /**
     * Get module configuration value
     *
     * @param string $moduleName
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function moduleConfig(string $moduleName, string $key, $default = null)
    {
        $setting = CacheHelper::remember(
            ['modules'],
            "module:config:{$moduleName}",
            3600,
            fn() => ModuleSetting::where('module_name', $moduleName)->first()
        );
        
        return $setting?->configuration[$key] ?? $default;
    }
}
