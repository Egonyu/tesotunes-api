<?php

namespace App\Settings;

use App\Settings\Enums\SettingType;
use App\Settings\Enums\SettingVisibility;

/**
 * Terse factory helpers for registering settings. Adds the most common
 * type+rules combo so definition files stay readable at 150+ keys.
 */
final class Define
{
    public static function bool(string $key, bool $default = false): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Boolean)
            ->default($default)
            ->rules(['boolean'])
            ->visibility(SettingVisibility::Admin);
    }

    public static function int(string $key, int $default = 0): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Integer)
            ->default($default)
            ->rules(['integer'])
            ->visibility(SettingVisibility::Admin);
    }

    public static function float(string $key, float $default = 0.0): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Float)
            ->default($default)
            ->rules(['numeric'])
            ->visibility(SettingVisibility::Admin);
    }

    public static function str(string $key, string $default = ''): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::String)
            ->default($default)
            ->rules(['nullable', 'string', 'max:500'])
            ->visibility(SettingVisibility::Admin);
    }

    /**
     * @param  array<int, string>  $options
     */
    public static function enum(string $key, array $options, string $default): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Enum)
            ->default($default)
            ->options($options)
            ->rules(['required', 'in:'.implode(',', $options)])
            ->visibility(SettingVisibility::Admin);
    }

    public static function email(string $key, string $default = ''): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Email)
            ->default($default)
            ->rules(['nullable', 'email:rfc'])
            ->visibility(SettingVisibility::Admin);
    }

    public static function url(string $key, string $default = ''): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Url)
            ->default($default)
            ->rules(['nullable', 'url'])
            ->visibility(SettingVisibility::Admin);
    }

    /**
     * Image URL setting — stored as a URL string; frontend renders a file-upload control.
     */
    public static function image(string $key, string $default = ''): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Image)
            ->default($default)
            ->rules(['nullable', 'string', 'max:2048'])
            ->visibility(SettingVisibility::Admin);
    }

    public static function secret(string $key): SettingDefinitionBuilder
    {
        return SettingDefinition::make($key)
            ->type(SettingType::Encrypted)
            ->default(null)
            ->rules(['nullable', 'string', 'max:500'])
            ->visibility(SettingVisibility::SuperAdmin)
            ->editableBy(['super_admin'])
            ->secret(true);
    }
}
