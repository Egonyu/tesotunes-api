<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\SettingAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::query()->firstOrCreate(['name' => 'admin'], [
        'display_name' => 'Admin', 'description' => 'Admin', 'is_active' => true, 'priority' => 5,
    ]);
    Role::query()->firstOrCreate(['name' => 'super_admin'], [
        'display_name' => 'Super Admin', 'description' => 'Super', 'is_active' => true, 'priority' => 10,
    ]);
});

function settingsAdminUser(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role, $user->id);
    $user->clearPermissionCache();

    return $user->fresh();
}

test('schema endpoint returns definitions visible to admin and masks secrets', function () {
    $this->actingAs(settingsAdminUser('admin'))
        ->getJson('/api/admin/settings/schema')
        ->assertOk()
        ->assertJsonPath('success', true);

    $resp = $this->actingAs(settingsAdminUser('admin'))->getJson('/api/admin/settings/schema');
    $defs = collect($resp->json('data'));

    expect($defs->where('key', 'general_platform_name')->first())->not->toBeNull();
    expect($defs->where('key', 'users_user_registration_enabled')->first())->not->toBeNull();
    expect($defs->where('key', 'payments_mtn_api_key')->first())->toBeNull();
});

test('schema endpoint exposes super-admin definitions only to super admins', function () {
    $resp = $this->actingAs(settingsAdminUser('super_admin'))->getJson('/api/admin/settings/schema');
    $defs = collect($resp->json('data'));

    $secret = $defs->where('key', 'payments_mtn_api_key')->first();
    expect($secret)->not->toBeNull();
    expect($secret['secret'])->toBeTrue();
    expect($secret['default'])->toBeNull();
});

test('values endpoint returns configured flag without leaking secrets', function () {
    Setting::set('general_platform_name', 'TestSite');
    Setting::set('payments_mtn_api_key', 'super-secret-key');

    $resp = $this->actingAs(settingsAdminUser('super_admin'))->getJson('/api/admin/settings/values');
    $values = collect($resp->json('data'))->keyBy('key');

    expect($values['general_platform_name']['value'])->toBe('TestSite');
    expect($values['general_platform_name']['configured'])->toBeTrue();
    expect($values['general_platform_name']['version'])->toBe(1);

    expect($values['payments_mtn_api_key']['value'])->toBeNull();
    expect($values['payments_mtn_api_key']['configured'])->toBeTrue();
    expect($values['payments_mtn_api_key']['version'])->toBe(1);
});

test('values endpoint reports unconfigured and version 0 before any write', function () {
    $resp = $this->actingAs(settingsAdminUser('super_admin'))->getJson('/api/admin/settings/values');
    $values = collect($resp->json('data'))->keyBy('key');

    expect($values['general_platform_name']['configured'])->toBeFalse();
    expect($values['general_platform_name']['version'])->toBe(0);
    expect($values['general_platform_name']['last_updated_by'])->toBeNull();
});

test('patch one response configured matches values endpoint', function () {
    $admin = settingsAdminUser('admin');

    $patchResp = $this->actingAs($admin)
        ->patchJson('/api/admin/settings/general_platform_name', ['value' => 'Aligned'])
        ->assertOk();

    $valuesResp = $this->actingAs($admin)->getJson('/api/admin/settings/values');
    $row = collect($valuesResp->json('data'))->keyBy('key')['general_platform_name'];

    expect($patchResp->json('data.configured'))->toBeTrue();
    expect($patchResp->json('data.version'))->toBe($row['version']);
});

test('patch one updates value, increments version, audits with actor', function () {
    $admin = settingsAdminUser('admin');

    $this->actingAs($admin)
        ->patchJson('/api/admin/settings/general_platform_name', ['value' => 'NewName'])
        ->assertOk()
        ->assertJsonPath('data.value', 'NewName')
        ->assertJsonPath('data.version', 1);

    expect(Setting::get('general_platform_name'))->toBe('NewName');

    $audit = SettingAudit::query()->where('setting_key', 'general_platform_name')->latest('id')->first();
    expect($audit->actor_user_id)->toBe($admin->id);
    expect($audit->new_value)->toBe('NewName');
});

test('patch one rejects bad input with 422', function () {
    $this->actingAs(settingsAdminUser('admin'))
        ->patchJson('/api/admin/settings/users_registration_limit_per_ip', ['value' => 9999])
        ->assertStatus(422);
});

test('patch one rejects on version mismatch with 409', function () {
    $admin = settingsAdminUser('admin');

    $this->actingAs($admin)->patchJson('/api/admin/settings/general_platform_name', ['value' => 'v1']);

    $this->actingAs($admin)
        ->patchJson('/api/admin/settings/general_platform_name', [
            'value' => 'v2',
            'expected_version' => 99,
        ])
        ->assertStatus(409)
        ->assertJsonPath('current_version', 1);
});

test('patch one forbids admin from updating super-admin-only secret', function () {
    $this->actingAs(settingsAdminUser('admin'))
        ->patchJson('/api/admin/settings/payments_mtn_api_key', ['value' => 'sneaky'])
        ->assertStatus(403);
});

test('patch one allows super admin to update secret and audits with redaction', function () {
    $super = settingsAdminUser('super_admin');

    $this->actingAs($super)
        ->patchJson('/api/admin/settings/payments_mtn_api_key', ['value' => 'rotated'])
        ->assertOk();

    $audit = SettingAudit::query()->where('setting_key', 'payments_mtn_api_key')->latest('id')->first();
    expect($audit->was_secret)->toBeTrue();
    expect($audit->new_value)->toBeNull();
    expect($audit->actor_user_id)->toBe($super->id);
});

test('batch patch rolls back when any single update fails', function () {
    $admin = settingsAdminUser('admin');

    Setting::set('general_platform_name', 'OriginalName');

    $resp = $this->actingAs($admin)->patchJson('/api/admin/settings', [
        'updates' => [
            'general_platform_name' => ['value' => 'Attempted'],
            'users_registration_limit_per_ip' => ['value' => 9999],
        ],
    ]);

    $resp->assertStatus(422);
    expect(Setting::get('general_platform_name'))->toBe('OriginalName');
});

test('batch patch commits when all updates pass', function () {
    $admin = settingsAdminUser('admin');

    $this->actingAs($admin)
        ->patchJson('/api/admin/settings', [
            'updates' => [
                'general_platform_name' => ['value' => 'BatchSite'],
                'users_user_registration_enabled' => ['value' => false],
            ],
            'reason' => 'batch test',
        ])
        ->assertOk();

    expect(Setting::get('general_platform_name'))->toBe('BatchSite');
    expect(Setting::get('users_user_registration_enabled'))->toBeFalse();
});

test('history endpoint returns paginated audit feed for a key', function () {
    $admin = settingsAdminUser('admin');

    foreach (['a', 'b', 'c'] as $val) {
        $this->actingAs($admin)->patchJson('/api/admin/settings/general_platform_name', ['value' => $val]);
    }

    $resp = $this->actingAs($admin)
        ->getJson('/api/admin/settings/general_platform_name/history?per_page=2')
        ->assertOk();

    expect($resp->json('meta.total'))->toBe(3);
    expect($resp->json('meta.per_page'))->toBe(2);
    expect(count($resp->json('data')))->toBe(2);
});

test('revert restores prior value and chains via reverted_from', function () {
    $admin = settingsAdminUser('admin');

    $this->actingAs($admin)->patchJson('/api/admin/settings/general_platform_name', ['value' => 'A']);
    $this->actingAs($admin)->patchJson('/api/admin/settings/general_platform_name', ['value' => 'B']);

    $secondAudit = SettingAudit::query()
        ->where('setting_key', 'general_platform_name')
        ->orderBy('id')
        ->skip(1)
        ->first();

    expect($secondAudit->old_value)->toBe('A');

    $this->actingAs($admin)
        ->postJson("/api/admin/settings/general_platform_name/revert/{$secondAudit->id}")
        ->assertOk();

    expect(Setting::get('general_platform_name'))->toBe('A');

    $latest = SettingAudit::query()
        ->where('setting_key', 'general_platform_name')
        ->latest('id')->first();
    expect($latest->reverted_from)->toBe($secondAudit->id);
});

test('revert refuses when no prior state exists (first audit row)', function () {
    $admin = settingsAdminUser('admin');

    $this->actingAs($admin)->patchJson('/api/admin/settings/general_platform_name', ['value' => 'A']);

    $first = SettingAudit::query()
        ->where('setting_key', 'general_platform_name')
        ->orderBy('id')->first();

    $this->actingAs($admin)
        ->postJson("/api/admin/settings/general_platform_name/revert/{$first->id}")
        ->assertStatus(409);
});

test('revert refuses to restore secret values', function () {
    $super = settingsAdminUser('super_admin');

    $this->actingAs($super)->patchJson('/api/admin/settings/payments_mtn_api_key', ['value' => 'a']);

    $audit = SettingAudit::query()->where('setting_key', 'payments_mtn_api_key')->first();

    $this->actingAs($super)
        ->postJson("/api/admin/settings/payments_mtn_api_key/revert/{$audit->id}")
        ->assertStatus(409);
});

test('public settings endpoint serves visibility=public keys without auth', function () {
    Setting::set('general_platform_name', 'PublicSite');

    $resp = $this->getJson('/api/settings/public')->assertOk();
    $data = collect($resp->json('data'))->keyBy('key');

    expect($data->has('general_platform_name'))->toBeTrue();
    expect($data['general_platform_name']['value'])->toBe('PublicSite');
    expect($data->has('payments_mtn_api_key'))->toBeFalse();
    expect($data->has('users_user_registration_enabled'))->toBeFalse();
});

test('public settings endpoint excludes deprecated keys', function () {
    $resp = $this->getJson('/api/settings/public');
    $keys = collect($resp->json('data'))->pluck('key');

    expect($keys)->not->toContain('appearance_sacco_name');
});
