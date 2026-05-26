<?php

use App\Models\Setting;
use App\Settings\Enums\SettingType;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingDefinition;
use App\Settings\SettingRegistry;
use App\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    app(SettingRegistry::class)->clear();

    SettingDefinition::make('cache_test_key')
        ->group('cache_test')
        ->type(SettingType::String)
        ->default('default-string')
        ->rules(['nullable', 'string', 'max:100'])
        ->visibility(SettingVisibility::Admin)
        ->register();

    app(SettingsManager::class)->flushRequestCache();
});

test('second read for a registered key skips the database', function () {
    Setting::set('cache_test_key', 'cached-value');
    app(SettingsManager::class)->flushRequestCache();

    DB::enableQueryLog();
    expect(Setting::get('cache_test_key'))->toBe('cached-value');
    $firstReadQueries = count(DB::getQueryLog());
    expect($firstReadQueries)->toBeGreaterThan(0);

    app(SettingsManager::class)->flushRequestCache();
    DB::flushQueryLog();

    expect(Setting::get('cache_test_key'))->toBe('cached-value');
    expect(count(DB::getQueryLog()))->toBe(0);
});

test('cache miss for unset key still resolves to default without re-querying', function () {
    DB::enableQueryLog();

    expect(Setting::get('cache_test_key'))->toBe('default-string');
    $firstReadQueries = count(DB::getQueryLog());

    app(SettingsManager::class)->flushRequestCache();
    DB::flushQueryLog();

    expect(Setting::get('cache_test_key'))->toBe('default-string');
    expect(count(DB::getQueryLog()))->toBe(0);
    expect($firstReadQueries)->toBeGreaterThan(0);
});

test('writes invalidate the cache so subsequent reads see new value', function () {
    Setting::set('cache_test_key', 'v1');
    app(SettingsManager::class)->flushRequestCache();
    expect(Setting::get('cache_test_key'))->toBe('v1');

    Setting::set('cache_test_key', 'v2');
    app(SettingsManager::class)->flushRequestCache();
    expect(Setting::get('cache_test_key'))->toBe('v2');
});
