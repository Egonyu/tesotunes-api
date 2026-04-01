<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminRolesApiRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $permissionDefaults = array_filter([
            'is_active' => Schema::hasColumn('permissions', 'is_active') ? true : null,
        ], fn ($value) => $value !== null);

        $manageRoles = Permission::query()->updateOrCreate(['slug' => 'manage-roles'], [
            'name' => 'Manage Roles',
            'description' => 'Permission to manage roles',
            'group' => 'admin',
            ...$permissionDefaults,
        ]);

        $editContent = Permission::query()->updateOrCreate(['slug' => 'edit-content'], [
            'name' => 'Edit Content',
            'description' => 'Permission to edit content',
            'group' => 'content',
            ...$permissionDefaults,
        ]);

        $artistStudio = Permission::query()->updateOrCreate(['slug' => 'artist.studio'], [
            'name' => 'Artist Studio',
            'description' => 'Access the artist studio',
            'group' => 'artist',
            ...$permissionDefaults,
        ]);

        $adminRole = Role::query()->updateOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description' => 'Administrator access',
            'permissions' => ['manage-roles'],
            'is_active' => true,
            'priority' => 80,
        ]);
        $adminRole->permissions()->sync([$manageRoles->id]);

        $moderatorRole = Role::query()->updateOrCreate(['name' => 'moderator'], [
            'display_name' => 'Moderator',
            'description' => 'Moderation access',
            'permissions' => ['edit-content'],
            'is_active' => true,
            'priority' => 60,
        ]);
        $moderatorRole->permissions()->sync([$editContent->id]);

        $artistRole = Role::query()->updateOrCreate(['name' => 'artist'], [
            'display_name' => 'Artist',
            'description' => 'Artist access',
            'permissions' => ['artist.studio'],
            'is_active' => true,
            'priority' => 40,
        ]);
        $artistRole->permissions()->sync([$artistStudio->id]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin', $this->admin->id);
        $this->admin->clearPermissionCache();
    }

    public function test_admin_roles_endpoints_serialize_permission_relations_without_colliding_with_json_permissions_attribute(): void
    {
        $rolesResponse = $this->actingAs($this->admin)->getJson('/api/admin/roles');

        $rolesResponse->assertOk()
            ->assertJsonPath('success', true);

        $moderatorRole = collect($rolesResponse->json('data', []))
            ->firstWhere('name', 'moderator');

        $this->assertNotNull($moderatorRole);
        $this->assertSame(['edit-content'], $moderatorRole['permissions']);
        $this->assertSame('edit-content', $moderatorRole['permission_details'][0]['key'] ?? null);

        $templatesResponse = $this->actingAs($this->admin)->getJson('/api/admin/role-templates');

        $templatesResponse->assertOk()
            ->assertJsonPath('success', true);

        $moderatorTemplate = collect($templatesResponse->json('data', []))
            ->firstWhere('key', 'moderator_base');

        $this->assertNotNull($moderatorTemplate);
        $this->assertContains('edit-content', $moderatorTemplate['permissions']);
    }

    public function test_admin_users_endpoint_serializes_active_role_permissions_without_map_on_array_errors(): void
    {
        $managedUser = User::factory()->create();
        $managedUser->assignRole('moderator', $this->admin->id);
        $managedUser->clearPermissionCache();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $managedUserPayload = collect($response->json('data', []))
            ->firstWhere('id', $managedUser->id);

        $this->assertNotNull($managedUserPayload);
        $this->assertContains('edit-content', $managedUserPayload['active_roles'][0]['permissions'] ?? []);
    }
}
