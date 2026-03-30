<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use HandlesApiErrors;

    private const ROLE_TEMPLATE_DEFINITIONS = [
        [
            'key' => 'moderator_base',
            'label' => 'Moderator Template',
            'description' => 'Full existing moderator setup as a starting point.',
            'base_role_name' => 'moderator',
            'role_name' => 'moderator_template',
            'display_name' => 'Moderator Template',
            'role_description' => 'General moderation role cloned from the existing moderator access bundle.',
        ],
        [
            'key' => 'content_moderator',
            'label' => 'Content Moderator',
            'description' => 'Focused on reports, content review, and user moderation.',
            'base_role_name' => 'moderator',
            'role_name' => 'content_moderator',
            'display_name' => 'Content Moderator',
            'role_description' => 'Moderates platform content, reports, and flagged users.',
            'permission_keywords' => ['content', 'report', 'user', 'comment', 'music'],
        ],
        [
            'key' => 'forum_moderator',
            'label' => 'Forum Moderator',
            'description' => 'Keeps community discussions and user behavior in check.',
            'base_role_name' => 'moderator',
            'role_name' => 'forum_moderator',
            'display_name' => 'Forum Moderator',
            'role_description' => 'Moderates community conversations, flagged posts, and account conduct.',
            'permission_keywords' => ['content', 'report', 'comment', 'user'],
        ],
        [
            'key' => 'catalog_moderator',
            'label' => 'Catalog Moderator',
            'description' => 'Handles catalog intake review and ownership claim triage.',
            'base_role_name' => 'moderator',
            'role_name' => 'catalog_moderator',
            'display_name' => 'Catalog Moderator',
            'role_description' => 'Reviews uploaded catalog content and resolves ownership claims.',
            'permission_prefixes' => ['catalog.'],
            'permission_keywords' => ['report', 'content'],
        ],
        [
            'key' => 'admin_template',
            'label' => 'Admin Template',
            'description' => 'Broad admin starter copied from the current admin role.',
            'base_role_name' => 'admin',
            'role_name' => 'admin_template',
            'display_name' => 'Admin Template',
            'role_description' => 'Broad administrative role cloned from the existing admin access bundle.',
        ],
        [
            'key' => 'artist_template',
            'label' => 'Artist Template',
            'description' => 'Operational starter copied from the current artist role.',
            'base_role_name' => 'artist',
            'role_name' => 'artist_template',
            'display_name' => 'Artist Template',
            'role_description' => 'Artist operations role cloned from the existing artist access bundle.',
        ],
    ];

    private function transformPermission(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'key' => $permission->slug ?: $permission->name,
            'name' => $permission->name,
            'description' => $permission->description,
            'group' => $permission->group,
        ];
    }

    private function transformRole(Role $role, bool $includeUsers = false): array
    {
        $permissionRecords = $role->permissionRecords();

        $payload = [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'priority' => $role->priority,
            'is_active' => $role->is_active,
            'users_count' => $role->users_count ?? $role->users()->count(),
            'permissions' => $role->permissionKeys(),
            'permission_details' => $permissionRecords
                ->map(fn (Permission $permission) => $this->transformPermission($permission))
                ->values()
                ->all(),
            'is_system' => in_array($role->name, ['user', 'artist', 'moderator', 'admin', 'super_admin'], true),
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];

        if ($includeUsers) {
            $payload['users'] = $role->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => (bool) $user->is_active,
            ])->values()->all();
        }

        return $payload;
    }

    private function filterTemplatePermissions(array $permissions, array $template): array
    {
        $prefixes = $template['permission_prefixes'] ?? [];
        $keywords = $template['permission_keywords'] ?? [];

        if ($prefixes === [] && $keywords === []) {
            return $permissions;
        }

        return array_values(array_filter($permissions, function (string $permission) use ($prefixes, $keywords) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }

            foreach ($keywords as $keyword) {
                if (str_contains($permission, $keyword)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function transformTemplate(RoleTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key,
            'label' => $template->label,
            'description' => $template->description,
            'base_role_name' => $template->base_role_name,
            'role_name' => $template->role_name,
            'display_name' => $template->display_name,
            'role_description' => $template->role_description,
            'priority' => $template->priority,
            'is_active' => $template->is_active,
            'permissions' => $template->permissions ?? [],
            'is_system' => $template->is_system,
            'created_at' => $template->created_at,
            'updated_at' => $template->updated_at,
        ];
    }

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
                ->map(fn (Role $role) => $this->transformRole($role));

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
                'data' => $this->transformRole($role, true),
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
                'permissions.*' => 'string|exists:permissions,slug',
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

            $permissions = Permission::whereIn('slug', $validated['permissions'])->pluck('id');
            $role->permissions()->sync($permissions);

            return response()->json([
                'success' => true,
                'data' => $this->transformRole($role->load('permissions')),
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
                'permissions.*' => 'string|exists:permissions,slug',
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

            $permissions = Permission::whereIn('slug', $validated['permissions'])->pluck('id');
            $role->permissions()->sync($permissions);

            return response()->json([
                'success' => true,
                'data' => $this->transformRole($role->load('permissions')),
                'message' => 'Role updated successfully.',
            ]);
        }, 'Failed to update role.');
    }

    /**
     * Update role permissions only.
     */
    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $role) {
            if ($role->name === 'super_admin' && ! $request->user()->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot modify super admin role.',
                ], 403);
            }

            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'string|exists:permissions,slug',
            ]);

            $role->update([
                'permissions' => $validated['permissions'],
            ]);

            $permissions = Permission::whereIn('slug', $validated['permissions'])->pluck('id');
            $role->permissions()->sync($permissions);

            return response()->json([
                'success' => true,
                'data' => $this->transformRole($role->load('permissions')),
                'message' => 'Role permissions updated successfully.',
            ]);
        }, 'Failed to update role permissions.');
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
     * Assign a role to multiple users.
     */
    public function assignToUsers(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'integer|exists:users,id',
                'role_name' => 'required|exists:roles,name',
                'expires_at' => 'nullable|date|after:now',
            ]);

            $currentUser = $request->user();

            if ($validated['role_name'] === 'super_admin' && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot assign super admin role.',
                ], 403);
            }

            $users = User::whereIn('id', $validated['user_ids'])->get();
            $processed = [];

            foreach ($users as $user) {
                if (! $currentUser->canManageUser($user)) {
                    continue;
                }

                $user->assignRole(
                    $validated['role_name'],
                    $currentUser->id,
                    isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : null
                );

                $processed[] = $user->id;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'role_name' => $validated['role_name'],
                    'processed_user_ids' => $processed,
                    'processed_count' => count($processed),
                ],
                'message' => 'Role assigned successfully.',
            ]);
        }, 'Failed to assign role to users.');
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
     * Remove a role from multiple users.
     */
    public function removeFromUsers(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'integer|exists:users,id',
                'role_name' => 'required|exists:roles,name',
            ]);

            $currentUser = $request->user();

            if ($validated['role_name'] === 'super_admin' && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove super admin role.',
                ], 403);
            }

            $users = User::whereIn('id', $validated['user_ids'])->get();
            $processed = [];

            foreach ($users as $user) {
                if (! $currentUser->canManageUser($user)) {
                    continue;
                }

                $user->removeRole($validated['role_name']);
                $processed[] = $user->id;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'role_name' => $validated['role_name'],
                    'processed_user_ids' => $processed,
                    'processed_count' => count($processed),
                ],
                'message' => 'Role removed successfully.',
            ]);
        }, 'Failed to remove role from users.');
    }

    /**
     * Get available permissions.
     */
    public function permissions(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $permissions = Permission::query();

            if (\Illuminate\Support\Facades\Schema::hasColumn('permissions', 'is_active')) {
                $permissions->where('is_active', true);
            }

            $permissions = $permissions
                ->orderBy('group')
                ->orderBy('slug')
                ->get()
                ->groupBy(fn ($permission) => $permission->group ?? 'general')
                ->map(fn ($groupPermissions) => $groupPermissions
                    ->map(fn (Permission $permission) => $this->transformPermission($permission))
                    ->values()
                    ->all());

            return response()->json(['success' => true, 'data' => $permissions]);
        }, 'Failed to retrieve permissions.');
    }

    /**
     * Get reusable role templates built from existing system roles.
     */
    public function templates(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $roles = Role::with('permissions')
                ->whereIn('name', collect(self::ROLE_TEMPLATE_DEFINITIONS)->pluck('base_role_name')->filter()->unique()->all())
                ->get()
                ->keyBy('name');

            $systemTemplates = collect(self::ROLE_TEMPLATE_DEFINITIONS)
                ->map(function (array $template) use ($roles) {
                    $baseRole = $roles->get($template['base_role_name']);

                    if (! $baseRole) {
                        return null;
                    }

                    $basePermissions = $baseRole->permissionKeys();

                    return [
                        'key' => $template['key'],
                        'label' => $template['label'],
                        'description' => $template['description'],
                        'base_role_name' => $template['base_role_name'],
                        'role_name' => $template['role_name'],
                        'display_name' => $template['display_name'],
                        'role_description' => $template['role_description'],
                        'priority' => $baseRole->priority,
                        'is_active' => $baseRole->is_active,
                        'permissions' => $this->filterTemplatePermissions($basePermissions, $template),
                    ];
                })
                ->filter()
                ->values();

            $customTemplates = RoleTemplate::query()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (RoleTemplate $template) => $this->transformTemplate($template))
                ->values();

            return response()->json([
                'success' => true,
                'data' => $systemTemplates->concat($customTemplates)->values(),
            ]);
        }, 'Failed to retrieve role templates.');
    }

    /**
     * Store a reusable custom role template.
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'key' => 'required|string|max:255|unique:role_templates,key',
                'label' => 'required|string|max:255',
                'description' => 'nullable|string',
                'base_role_name' => 'nullable|string|max:255',
                'role_name' => 'required|string|max:255',
                'display_name' => 'required|string|max:255',
                'role_description' => 'nullable|string',
                'priority' => 'required|integer|min:0|max:10',
                'is_active' => 'boolean',
                'permissions' => 'required|array|min:1',
                'permissions.*' => 'string|exists:permissions,slug',
            ]);

            $template = RoleTemplate::create([
                ...$validated,
                'is_active' => $validated['is_active'] ?? true,
                'is_system' => false,
                'created_by' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->transformTemplate($template),
                'message' => 'Role template created successfully.',
            ], 201);
        }, 'Failed to create role template.');
    }

    /**
     * Delete a reusable custom role template.
     */
    public function destroyTemplate(RoleTemplate $roleTemplate): JsonResponse
    {
        return $this->handleApiAction(function () use ($roleTemplate) {
            if ($roleTemplate->is_system) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a system role template.',
                ], 403);
            }

            $roleTemplate->delete();

            return response()->json([
                'success' => true,
                'message' => 'Role template deleted successfully.',
            ]);
        }, 'Failed to delete role template.');
    }
}
