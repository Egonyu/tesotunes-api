<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

/**
 * Service class for handling role and permission management
 *
 * This service manages:
 * - Role creation, updating, and deletion
 * - Permission assignment and validation
 * - User role assignments
 * - Role hierarchy and inheritance
 * - Permission checking and caching
 * - Role-based access control (RBAC)
 */
class RoleService
{
    // System role constants
    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_ADMIN = 'admin';
    const ROLE_MODERATOR = 'moderator';
    const ROLE_ARTIST = 'artist';
    const ROLE_USER = 'user';

    // Permission categories
    const PERMISSION_CATEGORIES = [
        'music' => 'Music Management',
        'user' => 'User Management',
        'playlist' => 'Playlist Management',
        'comment' => 'Comment Management',
        'admin' => 'Admin Panel',
        'analytics' => 'Analytics',
        'follow' => 'Social Features',
        'system' => 'System Management',
    ];

    /**
     * Get all roles with optional filtering
     */
    public function getRoles(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Role::withCount('users');

        // Apply search filter
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('display_name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Apply status filter
        if (isset($filters['status'])) {
            $isActive = $filters['status'] === 'active';
            $query->where('is_active', $isActive);
        }

        // Sort by priority by default
        $query->orderBy('priority', 'desc')->orderBy('name');

        return $query->paginate($perPage);
    }

    /**
     * Create a new role
     */
    public function createRole(array $roleData): Role
    {
        // Validate role data
        $this->validateRoleData($roleData);

        // Check if role name already exists
        if (Role::where('name', $roleData['name'])->exists()) {
            throw new Exception("Role with name '{$roleData['name']}' already exists");
        }

        DB::beginTransaction();

        try {
            $role = Role::create([
                'name' => $roleData['name'],
                'display_name' => $roleData['display_name'],
                'description' => $roleData['description'] ?? null,
                'priority' => $roleData['priority'] ?? 0,
                'is_active' => $roleData['is_active'] ?? true,
                'permissions' => $roleData['permissions'] ?? [],
            ]);

            // Clear permission cache
            $this->clearPermissionCache();

            DB::commit();

            return $role;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing role
     */
    public function updateRole(Role $role, array $updateData): Role
    {
        // Prevent modification of system roles
        if ($this->isSystemRole($role->name)) {
            $this->validateSystemRoleUpdate($role, $updateData);
        }

        DB::beginTransaction();

        try {
            $updateData = $this->prepareUpdateData($role, $updateData);
            $role->update($updateData);

            // Clear permission cache
            $this->clearPermissionCache();

            DB::commit();

            return $role->fresh();

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete a role
     */
    public function deleteRole(Role $role): bool
    {
        // Prevent deletion of system roles
        if ($this->isSystemRole($role->name)) {
            throw new Exception('System roles cannot be deleted');
        }

        // Check if role has users
        if ($role->users()->count() > 0) {
            throw new Exception('Cannot delete role that is assigned to users. Remove all users first.');
        }

        DB::beginTransaction();

        try {
            $role->delete();

            // Clear permission cache
            $this->clearPermissionCache();

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Assign role to user
     */
    public function assignRole(User $user, Role $role, ?User $assignedBy = null): bool
    {
        // Check if role is active
        if (!$role->is_active) {
            throw new Exception('Cannot assign inactive role');
        }

        // Check if user already has this role
        if ($user->hasRole($role->name)) {
            throw new Exception('User already has this role');
        }

        // Check assignment permissions
        if ($assignedBy && !$this->canAssignRole($assignedBy, $role)) {
            throw new Exception('You do not have permission to assign this role');
        }

        DB::beginTransaction();

        try {
            $user->roles()->attach($role->id, [
                'assigned_at' => now(),
                'assigned_by' => $assignedBy?->id,
                'is_active' => true,
            ]);

            // Clear user permission cache
            $this->clearUserPermissionCache($user->id);

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Remove role from user
     */
    public function removeRole(User $user, Role $role, ?User $removedBy = null): bool
    {
        // Prevent removal of essential system roles
        if ($this->isSystemRole($role->name) && $this->isLastUserWithRole($user, $role)) {
            throw new Exception('Cannot remove the last user with this system role');
        }

        // Check removal permissions
        if ($removedBy && !$this->canRemoveRole($removedBy, $role)) {
            throw new Exception('You do not have permission to remove this role');
        }

        DB::beginTransaction();

        try {
            $user->roles()->detach($role->id);

            // Clear user permission cache
            $this->clearUserPermissionCache($user->id);

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Check if user has specific permission
     */
    public function hasPermission(User $user, string $permission): bool
    {
        $cacheKey = "user_permissions_{$user->id}";

        $userPermissions = Cache::remember($cacheKey, 3600, function() use ($user) {
            return $this->getUserPermissions($user);
        });

        // Check for wildcard permission (super admin)
        if (in_array('*', $userPermissions)) {
            return true;
        }

        // Check for exact permission match
        if (in_array($permission, $userPermissions)) {
            return true;
        }

        // Check for wildcard patterns
        foreach ($userPermissions as $userPermission) {
            if (str_contains($userPermission, '*')) {
                $pattern = str_replace('*', '.*', $userPermission);
                if (preg_match("/^{$pattern}$/", $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all permissions for a user
     */
    public function getUserPermissions(User $user): array
    {
        $permissions = [];

        foreach ($user->roles as $role) {
            if ($role->is_active && is_array($role->permissions)) {
                $permissions = array_merge($permissions, $role->permissions);
            }
        }

        return array_unique($permissions);
    }

    /**
     * Get users with a specific role
     */
    public function getUsersWithRole(string $roleName): Collection
    {
        return User::whereHas('roles', function($query) use ($roleName) {
            $query->where('name', $roleName)->where('is_active', true);
        })->get();
    }

    /**
     * Bulk assign roles to users
     */
    public function bulkAssignRoles(array $userIds, array $roleIds, ?User $assignedBy = null): array
    {
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($userIds as $userId) {
                $user = User::findOrFail($userId);

                foreach ($roleIds as $roleId) {
                    $role = Role::findOrFail($roleId);

                    try {
                        $this->assignRole($user, $role, $assignedBy);
                        $results[] = [
                            'user_id' => $userId,
                            'role_id' => $roleId,
                            'success' => true,
                            'message' => 'Role assigned successfully'
                        ];
                    } catch (Exception $e) {
                        $results[] = [
                            'user_id' => $userId,
                            'role_id' => $roleId,
                            'success' => false,
                            'message' => $e->getMessage()
                        ];
                    }
                }
            }

            DB::commit();

            return $results;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get role statistics
     */
    public function getRoleStatistics(): array
    {
        $roles = Role::withCount('users')->get();

        return [
            'total_roles' => $roles->count(),
            'active_roles' => $roles->where('is_active', true)->count(),
            'system_roles' => $roles->whereIn('name', [
                self::ROLE_SUPER_ADMIN,
                self::ROLE_ADMIN,
                self::ROLE_MODERATOR,
                self::ROLE_ARTIST,
                self::ROLE_USER
            ])->count(),
            'custom_roles' => $roles->whereNotIn('name', [
                self::ROLE_SUPER_ADMIN,
                self::ROLE_ADMIN,
                self::ROLE_MODERATOR,
                self::ROLE_ARTIST,
                self::ROLE_USER
            ])->count(),
            'total_assignments' => DB::table('user_roles')->count(),
            'role_distribution' => $roles->mapWithKeys(function($role) {
                return [$role->name => $role->users_count];
            }),
        ];
    }

    /**
     * Get default permissions for role types
     */
    public function getDefaultPermissions(string $roleType): array
    {
        $permissionSets = [
            self::ROLE_USER => [
                'music.play',
                'music.like',
                'music.share',
                'playlist.create',
                'playlist.edit_own',
                'playlist.delete_own',
                'comment.create',
                'comment.edit_own',
                'comment.delete_own',
                'follow.users',
                'follow.artists',
                'user.edit_own',
            ],

            self::ROLE_ARTIST => [
                // Include all user permissions
                ...($this->getDefaultPermissions(self::ROLE_USER)),
                'music.upload',
                'music.edit_own',
                'music.delete_own',
                'analytics.view_own',
            ],

            self::ROLE_MODERATOR => [
                // Include all artist permissions
                ...($this->getDefaultPermissions(self::ROLE_ARTIST)),
                'music.moderate',
                'music.approve',
                'comment.moderate',
                'comment.edit_any',
                'comment.delete_any',
                'user.moderate',
                'user.ban',
                'user.unban',
            ],

            self::ROLE_ADMIN => [
                // Include all moderator permissions
                ...($this->getDefaultPermissions(self::ROLE_MODERATOR)),
                'admin.dashboard',
                'admin.users',
                'admin.music',
                'admin.roles',
                'admin.permissions',
                'admin.settings',
                'admin.reports',
                'admin.analytics',
                'admin.payments',
                'user.edit_any',
                'user.delete_any',
                'music.edit_any',
                'music.delete_any',
                'music.feature',
                'analytics.view_any',
                'analytics.export',
                'system.settings',
            ],

            self::ROLE_SUPER_ADMIN => ['*'], // All permissions
        ];

        return $permissionSets[$roleType] ?? [];
    }

    /**
     * Get available permissions grouped by category
     */
    public function getAvailablePermissions(): array
    {
        return [
            'music' => [
                'music.play' => 'Play music',
                'music.like' => 'Like/unlike songs',
                'music.share' => 'Share music',
                'music.upload' => 'Upload music',
                'music.edit_own' => 'Edit own music',
                'music.edit_any' => 'Edit any music',
                'music.delete_own' => 'Delete own music',
                'music.delete_any' => 'Delete any music',
                'music.moderate' => 'Moderate music content',
                'music.approve' => 'Approve music for publication',
                'music.feature' => 'Feature music on platform',
            ],
            'user' => [
                'user.view' => 'View user profiles',
                'user.edit_own' => 'Edit own profile',
                'user.edit_any' => 'Edit any user profile',
                'user.delete_own' => 'Delete own account',
                'user.delete_any' => 'Delete any user account',
                'user.moderate' => 'Moderate user content',
                'user.ban' => 'Ban users',
                'user.unban' => 'Unban users',
                'user.impersonate' => 'Impersonate users',
            ],
            'playlist' => [
                'playlist.create' => 'Create playlists',
                'playlist.edit_own' => 'Edit own playlists',
                'playlist.edit_any' => 'Edit any playlist',
                'playlist.delete_own' => 'Delete own playlists',
                'playlist.delete_any' => 'Delete any playlist',
                'playlist.moderate' => 'Moderate playlists',
            ],
            'comment' => [
                'comment.create' => 'Create comments',
                'comment.edit_own' => 'Edit own comments',
                'comment.edit_any' => 'Edit any comment',
                'comment.delete_own' => 'Delete own comments',
                'comment.delete_any' => 'Delete any comment',
                'comment.moderate' => 'Moderate comments',
            ],
            'admin' => [
                'admin.dashboard' => 'Access admin dashboard',
                'admin.users' => 'Manage users',
                'admin.music' => 'Manage music',
                'admin.roles' => 'Manage roles',
                'admin.permissions' => 'Manage permissions',
                'admin.settings' => 'Manage settings',
                'admin.reports' => 'View reports',
                'admin.analytics' => 'View analytics',
                'admin.payments' => 'Manage payments',
                'admin.credits' => 'Manage credits',
            ],
            'analytics' => [
                'analytics.view_own' => 'View own analytics',
                'analytics.view_any' => 'View any analytics',
                'analytics.export' => 'Export analytics data',
            ],
            'follow' => [
                'follow.users' => 'Follow users',
                'follow.artists' => 'Follow artists',
            ],
            'system' => [
                'system.settings' => 'Manage system settings',
                'system.maintenance' => 'Perform maintenance',
                'system.backup' => 'Manage backups',
                'system.logs' => 'View system logs',
            ],
        ];
    }

    /**
     * Check if role name is a system role
     */
    protected function isSystemRole(string $roleName): bool
    {
        return in_array($roleName, [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_ADMIN,
            self::ROLE_MODERATOR,
            self::ROLE_ARTIST,
            self::ROLE_USER
        ]);
    }

    /**
     * Validate role data
     */
    protected function validateRoleData(array $data): void
    {
        if (empty($data['name'])) {
            throw new Exception('Role name is required');
        }

        if (!preg_match('/^[a-z_]+$/', $data['name'])) {
            throw new Exception('Role name must contain only lowercase letters and underscores');
        }

        if (empty($data['display_name'])) {
            throw new Exception('Display name is required');
        }

        if (isset($data['permissions']) && !is_array($data['permissions'])) {
            throw new Exception('Permissions must be an array');
        }
    }

    /**
     * Validate system role updates
     */
    protected function validateSystemRoleUpdate(Role $role, array $updateData): void
    {
        // Prevent renaming system roles
        if (isset($updateData['name']) && $updateData['name'] !== $role->name) {
            throw new Exception('System roles cannot be renamed');
        }

        // Prevent deactivating system roles
        if (in_array($role->name, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN])) {
            if (isset($updateData['is_active']) && !$updateData['is_active']) {
                throw new Exception('Essential system roles cannot be deactivated');
            }
        }
    }

    /**
     * Prepare update data for role
     */
    protected function prepareUpdateData(Role $role, array $updateData): array
    {
        $allowedFields = ['display_name', 'description', 'priority', 'permissions'];

        // Only allow name and is_active updates for non-system roles
        if (!$this->isSystemRole($role->name)) {
            $allowedFields[] = 'name';
            $allowedFields[] = 'is_active';
        }

        // Super admin always has all permissions
        if ($role->name === self::ROLE_SUPER_ADMIN) {
            $updateData['permissions'] = ['*'];
        }

        return array_intersect_key($updateData, array_flip($allowedFields));
    }

    /**
     * Check if user can assign a specific role
     */
    protected function canAssignRole(User $user, Role $role): bool
    {
        // Only admins and super admins can assign roles
        if (!$user->hasRole(self::ROLE_ADMIN) && !$user->hasRole(self::ROLE_SUPER_ADMIN)) {
            return false;
        }

        // Only super admins can assign super admin role
        if ($role->name === self::ROLE_SUPER_ADMIN && !$user->hasRole(self::ROLE_SUPER_ADMIN)) {
            return false;
        }

        return true;
    }

    /**
     * Check if user can remove a specific role
     */
    protected function canRemoveRole(User $user, Role $role): bool
    {
        return $this->canAssignRole($user, $role);
    }

    /**
     * Check if user is the last one with a specific role
     */
    protected function isLastUserWithRole(User $user, Role $role): bool
    {
        return $role->users()->where('user_id', '!=', $user->id)->count() === 0;
    }

    /**
     * Clear permission cache
     */
    protected function clearPermissionCache(): void
    {
        Cache::tags(['permissions'])->flush();
    }

    /**
     * Clear user-specific permission cache
     */
    protected function clearUserPermissionCache(int $userId): void
    {
        Cache::forget("user_permissions_{$userId}");
    }
}