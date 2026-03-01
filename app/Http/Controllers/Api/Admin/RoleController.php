<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use HandlesApiErrors;

    /**
     * Get all roles with their permissions and user counts.
     */
    public function index(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $roles = Role::with('permissions')
                ->withCount('users')
                ->orderBy('priority', 'desc')
                ->get()
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'priority' => $role->priority,
                    'is_active' => $role->is_active,
                    'users_count' => $role->users_count,
                    'permissions' => $role->permissions->pluck('name')->toArray(),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ]);

            return response()->json(['success' => true, 'data' => $roles]);
        }, 'Failed to retrieve roles.');
    }

    /**
     * Get a specific role with details.
     */
    public function show(Role $role): JsonResponse
    {
        return $this->handleApiAction(function () use ($role) {
            $role->load(['permissions', 'users' => function ($query) {
                $query->select(['id', 'name', 'email', 'role', 'is_active'])
                    ->limit(50);
            }]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'priority' => $role->priority,
                    'is_active' => $role->is_active,
                    'permissions' => $role->permissions,
                    'users' => $role->users,
                    'users_count' => $role->users()->count(),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ],
            ]);
        }, 'Failed to retrieve role details.');
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles',
                'display_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'priority' => 'required|integer|min:0|max:10',
                'permissions' => 'required|array',
                'permissions.*' => 'string|exists:permissions,name',
                'is_active' => 'boolean',
            ]);

            $role = Role::create([
                'name' => $validated['name'],
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?? null,
                'priority' => $validated['priority'],
                'permissions' => $validated['permissions'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $permissions = Permission::whereIn('name', $validated['permissions'])->pluck('id');
            $role->permissions()->sync($permissions);

            return response()->json([
                'success' => true,
                'data' => $role->load('permissions'),
                'message' => 'Role created successfully.',
            ], 201);
        }, 'Failed to create role.');
    }

    /**
     * Update a role.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $role) {
            if ($role->name === 'super_admin' && ! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot modify super admin role.',
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:roles,name,'.$role->id,
                'display_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'priority' => 'required|integer|min:0|max:10',
                'permissions' => 'required|array',
                'permissions.*' => 'string|exists:permissions,name',
                'is_active' => 'boolean',
            ]);

            $role->update([
                'name' => $validated['name'],
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?? null,
                'priority' => $validated['priority'],
                'permissions' => $validated['permissions'],
                'is_active' => $validated['is_active'] ?? $role->is_active,
            ]);

            $permissions = Permission::whereIn('name', $validated['permissions'])->pluck('id');
            $role->permissions()->sync($permissions);

            return response()->json([
                'success' => true,
                'data' => $role->load('permissions'),
                'message' => 'Role updated successfully.',
            ]);
        }, 'Failed to update role.');
    }

    /**
     * Delete a role.
     */
    public function destroy(Role $role): JsonResponse
    {
        return $this->handleApiAction(function () use ($role) {
            $systemRoles = ['user', 'artist', 'moderator', 'admin', 'super_admin'];
            if (in_array($role->name, $systemRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete system role.',
                ], 403);
            }

            if ($role->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete role with active users.',
                ], 422);
            }

            $role->delete();

            return response()->json(['success' => true, 'message' => 'Role deleted successfully.']);
        }, 'Failed to delete role.');
    }

    /**
     * Assign role to user.
     */
    public function assignToUser(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'role_name' => 'required|exists:roles,name',
                'expires_at' => 'nullable|date|after:now',
            ]);

            $user = User::findOrFail($validated['user_id']);
            $currentUser = $request->user();

            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to manage this user.',
                ], 403);
            }

            if ($validated['role_name'] === 'super_admin' && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot assign super admin role.',
                ], 403);
            }

            $user->assignRole(
                $validated['role_name'],
                $currentUser->id,
                isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : null
            );

            return response()->json([
                'success' => true,
                'data' => $user->load('activeRoles'),
                'message' => 'Role assigned successfully.',
            ]);
        }, 'Failed to assign role to user.');
    }

    /**
     * Remove role from user.
     */
    public function removeFromUser(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'role_name' => 'required|exists:roles,name',
            ]);

            $user = User::findOrFail($validated['user_id']);
            $currentUser = $request->user();

            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to manage this user.',
                ], 403);
            }

            if ($validated['role_name'] === 'super_admin' && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove super admin role.',
                ], 403);
            }

            $user->removeRole($validated['role_name']);

            return response()->json([
                'success' => true,
                'data' => $user->load('activeRoles'),
                'message' => 'Role removed successfully.',
            ]);
        }, 'Failed to remove role from user.');
    }

    /**
     * Get available permissions.
     */
    public function permissions(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $permissions = Permission::active()
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->groupBy('category');

            return response()->json(['success' => true, 'data' => $permissions]);
        }, 'Failed to retrieve permissions.');
    }
}
