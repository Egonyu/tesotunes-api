<?php

use App\Models\Role;
use App\Models\Sacco\SaccoSettings;
use App\Models\Setting;
use App\Models\SettingAudit;
use App\Models\User;
use App\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Role::query()->firstOrCreate(['name' => 'admin'], [
        'display_name' => 'Admin', 'description' => 'Admin', 'is_active' => true, 'priority' => 5,
    ]);
});

test('manager writes through sacco driver into sacco_settings table', function () {
    Setting::set('sacco_share_price_ugx', 75000);

    $row = SaccoSettings::query()->where('key', 'share_price_ugx')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->value)->toBe(75000);

    expect(app(SettingsManager::class)->get('sacco_share_price_ugx'))->toBe(75000);
});

test('sacco writes emit an audit row attributed to the actor', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin', $admin->id);

    Setting::withActor(
        $admin->id,
        fn () => Setting::set('sacco_annual_interest_rate', 14.5),
        'rate review',
    );

    $audit = SettingAudit::query()
        ->where('setting_key', 'sacco_annual_interest_rate')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_user_id)->toBe($admin->id);
    expect($audit->reason)->toBe('rate review');
    expect((float) $audit->new_value)->toBe(14.5);
    expect($audit->new_version)->toBe(1);
});

test('repeated sacco writes increment audited version', function () {
    Setting::set('sacco_share_price_ugx', 60000);
    Setting::set('sacco_share_price_ugx', 70000);
    Setting::set('sacco_share_price_ugx', 80000);

    $audits = SettingAudit::query()
        ->where('setting_key', 'sacco_share_price_ugx')
        ->orderBy('id')
        ->get();

    expect($audits)->toHaveCount(3);
    expect($audits[0]->new_version)->toBe(1);
    expect($audits[1]->new_version)->toBe(2);
    expect($audits[2]->new_version)->toBe(3);
    expect($audits[2]->old_version)->toBe(2);
});

test('default value is returned when no row exists', function () {
    expect(Setting::get('sacco_share_price_ugx'))->toBe(50000);
    expect(Setting::get('sacco_annual_interest_rate'))->toBe(12.0);
});

test('sacco keys appear in the admin schema and values endpoints', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin', $admin->id);
    $admin->clearPermissionCache();

    Setting::set('sacco_share_price_ugx', 65000);

    $schema = $this->actingAs($admin->fresh())->getJson('/api/admin/settings/schema')->assertOk();
    $keys = collect($schema->json('data'))->pluck('key');
    expect($keys)->toContain('sacco_share_price_ugx');

    $values = $this->actingAs($admin->fresh())->getJson('/api/admin/settings/values')->assertOk();
    $byKey = collect($values->json('data'))->keyBy('key');
    expect($byKey['sacco_share_price_ugx']['value'])->toBe(65000);
});

test('public endpoint exposes public sacco copy without auth', function () {
    Setting::set('sacco_sacco_name', 'PublicSACCO');

    $resp = $this->getJson('/api/settings/public')->assertOk();
    $byKey = collect($resp->json('data'))->keyBy('key');

    expect($byKey['sacco_sacco_name']['value'])->toBe('PublicSACCO');
    expect($byKey->has('sacco_share_price_ugx'))->toBeFalse();
});

test('patch endpoint saves sacco key and returns correct version', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin', $admin->id);
    $admin->clearPermissionCache();

    $this->actingAs($admin->fresh())
        ->patchJson('/api/admin/settings/sacco_share_price_ugx', ['value' => 75000])
        ->assertOk()
        ->assertJsonPath('data.value', 75000)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.configured', true);

    expect(app(SettingsManager::class)->get('sacco_share_price_ugx'))->toBe(75000);

    $audit = \App\Models\SettingAudit::query()
        ->where('setting_key', 'sacco_share_price_ugx')
        ->latest('id')
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_version)->toBe(1);
});

test('values endpoint reports configured and version correctly for sacco keys', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin', $admin->id);
    $admin->clearPermissionCache();

    $fresh = $admin->fresh();

    // Before any write: unconfigured, version 0
    $before = $this->actingAs($fresh)->getJson('/api/admin/settings/values')->assertOk();
    $beforeByKey = collect($before->json('data'))->keyBy('key');
    expect($beforeByKey['sacco_share_price_ugx']['configured'])->toBeFalse();
    expect($beforeByKey['sacco_share_price_ugx']['version'])->toBe(0);

    // After write via HTTP PATCH
    $this->actingAs($fresh)->patchJson('/api/admin/settings/sacco_share_price_ugx', ['value' => 65000]);

    $after = $this->actingAs($fresh)->getJson('/api/admin/settings/values')->assertOk();
    $afterByKey = collect($after->json('data'))->keyBy('key');
    expect($afterByKey['sacco_share_price_ugx']['configured'])->toBeTrue();
    expect($afterByKey['sacco_share_price_ugx']['version'])->toBe(1);
    expect($afterByKey['sacco_share_price_ugx']['value'])->toBe(65000);
});

test('patch sacco key increments version on repeated writes', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin', $admin->id);
    $admin->clearPermissionCache();
    $fresh = $admin->fresh();

    $this->actingAs($fresh)->patchJson('/api/admin/settings/sacco_annual_interest_rate', ['value' => 12.0]);
    $this->actingAs($fresh)->patchJson('/api/admin/settings/sacco_annual_interest_rate', ['value' => 14.5]);

    $resp = $this->actingAs($fresh)
        ->patchJson('/api/admin/settings/sacco_annual_interest_rate', ['value' => 16.0])
        ->assertOk();

    expect($resp->json('data.version'))->toBe(3);
    expect($resp->json('data.configured'))->toBeTrue();
});
