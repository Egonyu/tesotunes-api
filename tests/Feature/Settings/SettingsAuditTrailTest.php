<?php

use App\Models\Setting;
use App\Models\SettingAudit;
use App\Models\User;
use App\Settings\Enums\SettingType;
use App\Settings\Enums\SettingVisibility;
use App\Settings\SettingDefinition;
use App\Settings\SettingRegistry;
use App\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $registry = app(SettingRegistry::class);
    $registry->clear();

    SettingDefinition::make('audit_test_key')
        ->group('test')
        ->type(SettingType::String)
        ->default('initial')
        ->rules(['nullable', 'string', 'max:200'])
        ->visibility(SettingVisibility::Admin)
        ->auditCategory('test_category')
        ->register();

    SettingDefinition::make('audit_secret_key')
        ->group('test')
        ->type(SettingType::Encrypted)
        ->rules(['nullable', 'string'])
        ->visibility(SettingVisibility::SuperAdmin)
        ->editableBy(['super_admin'])
        ->secret(true)
        ->store(\App\Settings\Enums\SettingStore::DbEncrypted)
        ->auditCategory('test_category')
        ->register();

    app(SettingsManager::class)->flushRequestCache();
});

test('first write creates an audit row with version 1 and null old value', function () {
    Setting::set('audit_test_key', 'hello');

    $audit = SettingAudit::query()->where('setting_key', 'audit_test_key')->first();

    expect($audit)->not->toBeNull();
    expect($audit->old_value)->toBeNull();
    expect($audit->new_value)->toBe('hello');
    expect($audit->old_version)->toBeNull();
    expect($audit->new_version)->toBe(1);
    expect($audit->was_secret)->toBeFalse();
    expect($audit->audit_category)->toBe('test_category');
});

test('subsequent writes append audit rows and increment version', function () {
    Setting::set('audit_test_key', 'one');
    app(SettingsManager::class)->flushRequestCache();
    Setting::set('audit_test_key', 'two');
    app(SettingsManager::class)->flushRequestCache();
    Setting::set('audit_test_key', 'three');

    $audits = SettingAudit::query()
        ->where('setting_key', 'audit_test_key')
        ->orderBy('id')
        ->get();

    expect($audits)->toHaveCount(3);
    expect($audits[0]->old_value)->toBeNull();
    expect($audits[0]->new_value)->toBe('one');
    expect($audits[0]->new_version)->toBe(1);
    expect($audits[1]->old_value)->toBe('one');
    expect($audits[1]->new_value)->toBe('two');
    expect($audits[1]->old_version)->toBe(1);
    expect($audits[1]->new_version)->toBe(2);
    expect($audits[2]->new_version)->toBe(3);
});

test('no audit row is created when value is unchanged', function () {
    Setting::set('audit_test_key', 'same');
    app(SettingsManager::class)->flushRequestCache();
    Setting::set('audit_test_key', 'same');

    $count = SettingAudit::query()->where('setting_key', 'audit_test_key')->count();
    expect($count)->toBe(1);
});

test('secret writes record an audit row but redact old and new values', function () {
    Setting::set('audit_secret_key', 'first-secret');
    app(SettingsManager::class)->flushRequestCache();
    Setting::set('audit_secret_key', 'rotated-secret');

    $audits = SettingAudit::query()
        ->where('setting_key', 'audit_secret_key')
        ->orderBy('id')
        ->get();

    expect($audits)->toHaveCount(2);
    expect($audits[0]->was_secret)->toBeTrue();
    expect($audits[0]->old_value)->toBeNull();
    expect($audits[0]->new_value)->toBeNull();
    expect($audits[1]->was_secret)->toBeTrue();
    expect($audits[1]->new_value)->toBeNull();
    expect($audits[1]->new_version)->toBe(2);
});

test('actingAs attaches the actor id and reason to subsequent audits', function () {
    $user = User::factory()->create();

    Setting::withActor($user->id, function () {
        Setting::set('audit_test_key', 'with-actor');
    }, 'admin manual edit');

    $audit = SettingAudit::query()->where('setting_key', 'audit_test_key')->first();

    expect($audit->actor_user_id)->toBe($user->id);
    expect($audit->reason)->toBe('admin manual edit');
});

test('settings row last_updated_by and version reflect latest write', function () {
    $user = User::factory()->create();

    Setting::withActor($user->id, fn () => Setting::set('audit_test_key', 'a'));
    app(SettingsManager::class)->flushRequestCache();
    Setting::withActor($user->id, fn () => Setting::set('audit_test_key', 'b'));

    $row = Setting::query()->where('key', 'audit_test_key')->first();
    expect($row->version)->toBe(2);
    expect($row->last_updated_by)->toBe($user->id);
});

test('legacy unregistered keys are also audited via model events', function () {
    Setting::set('legacy_audit_key', 'legacy-value');

    $audit = SettingAudit::query()->where('setting_key', 'legacy_audit_key')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_value)->toBe('legacy-value');
    expect($audit->audit_category)->toBeNull();
});
