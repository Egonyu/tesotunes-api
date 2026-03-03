<?php

namespace Tests\Feature\Api\ImageUpload;

use App\Models\Role;
use App\Models\User;

/**
 * Helper trait for image upload tests that need users with specific roles.
 *
 * The RoleMiddleware checks the user_roles pivot table via $user->hasAnyRole(),
 * NOT a simple 'role' column on users. This trait provides helpers to create
 * users with proper role assignments.
 */
trait CreatesUsersWithRoles
{
    /**
     * Create a user and assign a role via the user_roles pivot table.
     */
    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create(['is_active' => true]);

        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['display_name' => ucfirst(str_replace('_', ' ', $roleName)), 'is_active' => true]
        );

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        // Clear the cached roles so hasAnyRole() picks up the new assignment
        cache()->forget("user:{$user->id}:roles");

        return $user;
    }
}
