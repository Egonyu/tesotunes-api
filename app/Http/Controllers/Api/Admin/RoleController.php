<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get all roles with their permissions and user counts.
     */
    public function index(): JsonResponse
    {
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

        return response()->json(['data' => $roles]);
    }

    /**
     * Get a specific role with details.
     */
    public function show(Role $role): JsonResponse
    {
        $role->load(['permissions', 'users' => function ($query) {
            $query->select(['id', 'name', 'email', 'role', 'is_active'])
                  ->limit(50);
        }]);

        return response()->json([
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
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
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
            'data' => $role->load('permissions'),
            'message' => 'Role created successfully.',
        ], 201);
    }

    /**
     * Update a role.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->name === 'super_admin' && !$request->user()->isSuperAdmin()) {
            return response()->json([
                'message' => 'Cannot modify super admin role.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
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
            'data' => $role->load('permissions'),
            'message' => 'Role updated successfully.',
        ]);
    }

    /**
     * Delete a role.
     */
    public function destroy(Role $role): JsonResponse
    {
        $systemRoles = ['user', 'artist', 'moderator', 'admin', 'super_admin'];
        if (in_array($role->name, $systemRoles)) {
            return response()->json([
                'message' => 'Cannot delete system role.',
            ], 403);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete role with active users.',
            ], 422);
        }

        $role->delete();

        return response()->json(null, 204);
    }

    /**
     * Assign role to user.
     */
    public function assignToUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_name' => 'required|exists:roles,name',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $currentUser = $request->user();

        if (!$currentUser->canManageUser($user)) {
            return response()->json([
                'message' => 'Insufficient permissions to manage this user.',
            ], 403);
        }

        if ($validated['role_name'] === 'super_admin' && !$currentUser->isSuperAdmin()) {
            return response()->json([
                'message' => 'Cannot assign super admin role.',
            ], 403);
        }

        $user->assignRole(
            $validated['role_name'],
            $currentUser->id,
            isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : null
        );

        return response()->json([
            'data' => $user->load('activeRoles'),
            'message' => 'Role assigned successfully.',
        ]);
    }

    /**
     * Remove role from user.
     */
    public function removeFromUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_name' => 'required|exists:roles,name',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $currentUser = $request->user();

        if (!$currentUser->canManageUser($user)) {
            return response()->json([
                'message' => 'Insufficient permissions to manage this user.',
            ], 403);
        }

        if ($validated['role_name'] === 'super_admin' && !$currentUser->isSuperAdmin()) {
            return response()->json([
                'message' => 'Cannot remove super admin role.',
            ], 403);
        }

        $user->removeRole($validated['role_name']);

        return response()->json([
            'data' => $user->load('activeRoles'),
            'message' => 'Role removed successfully.',
        ]);
    }

    /**
     * Get available permissions.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::active()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        return response()->json(['data' => $permissions]);
    }
}