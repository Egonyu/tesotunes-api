<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Str;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create permissions
        $permissions = [
            ['name' => 'Manage Users', 'slug' => 'manage-users', 'group' => 'users'],
            ['name' => 'View Users', 'slug' => 'view-users', 'group' => 'users'],
            ['name' => 'Edit Users', 'slug' => 'edit-users', 'group' => 'users'],
            ['name' => 'Delete Users', 'slug' => 'delete-users', 'group' => 'users'],
            
            ['name' => 'Manage Content', 'slug' => 'manage-content', 'group' => 'content'],
            ['name' => 'Publish Content', 'slug' => 'publish-content', 'group' => 'content'],
            ['name' => 'Edit Content', 'slug' => 'edit-content', 'group' => 'content'],
            ['name' => 'Delete Content', 'slug' => 'delete-content', 'group' => 'content'],
            
            ['name' => 'Upload Music', 'slug' => 'upload-music', 'group' => 'music'],
            ['name' => 'Manage Music', 'slug' => 'manage-music', 'group' => 'music'],
            ['name' => 'Delete Music', 'slug' => 'delete-music', 'group' => 'music'],
            
            ['name' => 'Manage Store', 'slug' => 'manage-store', 'group' => 'store'],
            ['name' => 'Create Products', 'slug' => 'create-products', 'group' => 'store'],
            ['name' => 'Manage Orders', 'slug' => 'manage-orders', 'group' => 'store'],
            
            ['name' => 'Manage Payments', 'slug' => 'manage-payments', 'group' => 'financial'],
            ['name' => 'View Reports', 'slug' => 'view-reports', 'group' => 'financial'],
            ['name' => 'Manage SACCO', 'slug' => 'manage-sacco', 'group' => 'financial'],
            
            ['name' => 'Moderate Content', 'slug' => 'moderate-content', 'group' => 'moderation'],
            ['name' => 'Ban Users', 'slug' => 'ban-users', 'group' => 'moderation'],
            ['name' => 'Manage Reports', 'slug' => 'manage-reports', 'group' => 'moderation'],
            
            ['name' => 'Manage Settings', 'slug' => 'manage-settings', 'group' => 'system'],
            ['name' => 'View Analytics', 'slug' => 'view-analytics', 'group' => 'system'],
            ['name' => 'Manage Roles', 'slug' => 'manage-roles', 'group' => 'system'],
        ];

        $permissionIds = [];
        foreach ($permissions as $perm) {
            $permission = Permission::firstOrCreate(
                ['slug' => $perm['slug']],
                [
                    'name' => $perm['name'],
                    'group' => $perm['group'],
                    'description' => 'Permission to ' . strtolower($perm['name']),
                ]
            );
            $permissionIds[$perm['slug']] = $permission->id;
        }

        // Create roles
        $superAdmin = Role::firstOrCreate(
            ['name' => 'Super Admin'],
            [
                'display_name' => 'Super Administrator',
                'description' => 'Full system access',
                'priority' => 100,
                'is_active' => true,
            ]
        );
        $superAdmin->permissions()->sync(array_values($permissionIds));

        $admin = Role::firstOrCreate(
            ['name' => 'Admin'],
            [
                'display_name' => 'Administrator',
                'description' => 'General administration access',
                'priority' => 80,
                'is_active' => true,
            ]
        );
        $admin->permissions()->sync([
            $permissionIds['manage-users'],
            $permissionIds['view-users'],
            $permissionIds['edit-users'],
            $permissionIds['manage-content'],
            $permissionIds['publish-content'],
            $permissionIds['edit-content'],
            $permissionIds['moderate-content'],
            $permissionIds['manage-reports'],
            $permissionIds['view-analytics'],
            $permissionIds['view-reports'],
        ]);

        $moderator = Role::firstOrCreate(
            ['name' => 'Moderator'],
            [
                'display_name' => 'Content Moderator',
                'description' => 'Content moderation access',
                'priority' => 60,
                'is_active' => true,
            ]
        );
        $moderator->permissions()->sync([
            $permissionIds['moderate-content'],
            $permissionIds['manage-reports'],
            $permissionIds['view-users'],
        ]);

        $artist = Role::firstOrCreate(
            ['name' => 'Artist'],
            [
                'display_name' => 'Artist',
                'description' => 'Music artist account',
                'priority' => 40,
                'is_active' => true,
            ]
        );
        $artist->permissions()->sync([
            $permissionIds['upload-music'],
            $permissionIds['manage-music'],
            $permissionIds['manage-store'],
            $permissionIds['create-products'],
        ]);

        $user = Role::firstOrCreate(
            ['name' => 'User'],
            [
                'display_name' => 'Regular User',
                'description' => 'Standard user account',
                'priority' => 20,
                'is_active' => true,
            ]
        );
        $user->permissions()->sync([
            $permissionIds['upload-music'],
        ]);

        // Record Label Manager Role
        $labelManager = Role::firstOrCreate(
            ['name' => 'Label Manager'],
            [
                'display_name' => 'Record Label Manager',
                'description' => 'Manages a record label and its artists',
                'priority' => 45,
                'is_active' => true,
            ]
        );
        $labelManager->permissions()->sync([
            $permissionIds['upload-music'],
            $permissionIds['manage-music'],
            $permissionIds['manage-store'],
            $permissionIds['create-products'],
            $permissionIds['view-reports'],
        ]);

        // Promoter Role
        $promoter = Role::firstOrCreate(
            ['name' => 'Promoter'],
            [
                'display_name' => 'Event Promoter',
                'description' => 'Promotes events and artists',
                'priority' => 35,
                'is_active' => true,
            ]
        );
        $promoter->permissions()->sync([
            $permissionIds['upload-music'],
            $permissionIds['create-products'],
        ]);

        // Producer Role
        $producer = Role::firstOrCreate(
            ['name' => 'Producer'],
            [
                'display_name' => 'Music Producer',
                'description' => 'Creates beats and produces music',
                'priority' => 38,
                'is_active' => true,
            ]
        );
        $producer->permissions()->sync([
            $permissionIds['upload-music'],
            $permissionIds['manage-music'],
            $permissionIds['create-products'],
        ]);

        // DJ Role
        $dj = Role::firstOrCreate(
            ['name' => 'DJ'],
            [
                'display_name' => 'Disc Jockey',
                'description' => 'DJ and playlist curator',
                'priority' => 36,
                'is_active' => true,
            ]
        );
        $dj->permissions()->sync([
            $permissionIds['upload-music'],
        ]);
    }
}
