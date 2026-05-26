<?php

use App\Models\Setting;
use App\Settings\Enums\SettingType;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingDefinition;
use App\Settings\SettingRegistry;
use App\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $registry = app(SettingRegistry::class);
    $registry->clear();

    SettingDefinition::make('test_string_key')
        ->group('test')
        ->type(SettingType::String)
        ->default('default-value')
        ->rules(['required', 'string', 'max:50'])
        ->visibility(SettingVisibility::Admin)
        ->label('Test string')
        ->register();

    SettingDefinition::make('test_bool_key')
        ->group('test')
        ->type(SettingType::Boolean)
        ->default(false)
        ->rules(['boolean'])
        ->visibility(SettingVisibility::Admin)
        ->register();

    SettingDefinition::make('test_int_key')
        ->group('test')
        ->type(SettingType::Integer)
        ->default(10)
        ->rules(['integer', 'min:0', 'max:1000'])
        ->visibility(SettingVisibility::Admin)
        ->register();

    app(SettingsManager::class)->flushRequestCache();
});

test('registered key returns default when no row exists', function () {
    expect(Setting::get('test_string_key'))->toBe('default-value');
    expect(Setting::get('test_bool_key'))->toBeFalse();
    expect(Setting::get('test_int_key'))->toBe(10);
});

test('registered key round-trips through the manager', function () {
    Setting::set('test_string_key', 'hello');

    app(SettingsManager::class)->flushRequestCache();

    expect(Setting::get('test_string_key'))->toBe('hello');
});

test('registered boolean casts on read regardless of stored shape', function () {
    Setting::set('test_bool_key', true);

    app(SettingsManager::class)->flushRequestCache();

    expect(Setting::get('test_bool_key'))->toBeTrue();
});

test('registered integer casts to int on read', function () {
    Setting::set('test_int_key', 42);

    app(SettingsManager::class)->flushRequestCache();

    expect(Setting::get('test_int_key'))->toBe(42);
});

test('registered key validates input and rejects invalid values', function () {
    expect(fn () => Setting::set('test_int_key', 9999))->toThrow(ValidationException::class);
    expect(fn () => Setting::set('test_string_key', str_repeat('x', 100)))->toThrow(ValidationException::class);
});

test('unregistered key uses legacy code path unchanged', function () {
    Setting::set('legacy_unregistered_key', 'legacy-value');

    expect(Setting::get('legacy_unregistered_key'))->toBe('legacy-value');
    expect(Setting::get('does_not_exist_at_all', 'fallback'))->toBe('fallback');
});

test('platform definitions register on boot', function () {
    \App\Settings\Definitions\PlatformSettings::register(app(SettingRegistry::class));

    $registry = app(SettingRegistry::class);

    expect($registry->has('general_platform_name'))->toBeTrue();
    expect($registry->get('general_platform_name')?->default)->toBe('TesoTunes');
    expect($registry->get('general_default_currency')?->options)->toContain('UGX');
});
