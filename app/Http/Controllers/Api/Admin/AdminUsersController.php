<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Traits\HandlesApiErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Consolidated admin users controller.
 *
 * Merged from the former AdminUsersController (route-wired, raw DB)
 * and UserManagementController (Eloquent, richer features).
 */
class AdminUsersController extends Controller
{
    use HandlesApiErrors;

    private const MANAGED_ROLE_NAMES = ['user', 'artist', 'moderator', 'admin', 'super_admin'];

    private const PRIVILEGED_ROLE_NAMES = ['admin', 'super_admin', 'super admin', 'Admin', 'Super Admin'];

    private const MANAGED_ROLE_DEFAULTS = [
        Role::USER => [
            'display_name' => 'User',
            'description' => 'Standard user account',
            'priority' => 20,
            'is_active' => true,
        ],
        Role::ARTIST => [
            'display_name' => 'Artist',
            'description' => 'Music artist account',
            'priority' => 40,
            'is_active' => true,
        ],
        Role::MODERATOR => [
            'display_name' => 'Moderator',
            'description' => 'Content moderation access',
            'priority' => 60,
            'is_active' => true,
        ],
        Role::ADMIN => [
            'display_name' => 'Administrator',
            'description' => 'General administration access',
            'priority' => 80,
            'is_active' => true,
        ],
        Role::SUPER_ADMIN => [
            'display_name' => 'Super Administrator',
            'description' => 'Full system access',
            'priority' => 100,
            'is_active' => true,
        ],
    ];

    private function isPrivilegedUser(User $user): bool
    {
        $directRole = strtolower(str_replace(' ', '_', (string) $user->role));

        return in_array($directRole, [Role::ADMIN, Role::SUPER_ADMIN], true)
            || $user->hasAnyRole(self::PRIVILEGED_ROLE_NAMES);
    }

    private function applyModeratorUserVisibility(Request $request, Builder $query): void
    {
        if (! $request->user()?->isModeratorOnly()) {
            return;
        }

        $query
            ->where(function (Builder $roleQuery) {
                $roleQuery
                    ->whereNull('role')
                    ->orWhereNotIn('role', self::PRIVILEGED_ROLE_NAMES);
            })
            ->whereDoesntHave('activeRoles', function (Builder $roleQuery) {
                $roleQuery->whereIn('name', self::PRIVILEGED_ROLE_NAMES);
            });
    }

    private function moderatorHiddenUserResponse(Request $request, User $user): ?JsonResponse
    {
        if (! $request->user()?->isModeratorOnly() || ! $this->isPrivilegedUser($user)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'User not found.',
        ], 404);
    }

    private function resolveManagedRole(string $roleName): Role
    {
        if (! in_array($roleName, self::MANAGED_ROLE_NAMES, true)) {
            return Role::where('name', $roleName)->firstOrFail();
        }

        return Role::firstOrCreate(
            ['name' => $roleName],
            self::MANAGED_ROLE_DEFAULTS[$roleName] ?? [
                'display_name' => str_replace('_', ' ', ucfirst($roleName)),
                'description' => ucfirst(str_replace('_', ' ', $roleName)).' role',
                'priority' => 1,
                'is_active' => true,
            ]
        );
    }

    private function buildArtistReference(User $user): ?array
    {
        $artist = $user->artist;

        if (! $artist) {
            return null;
        }

        return [
            'id' => $artist->id,
            'stage_name' => $artist->stage_name,
            'slug' => $artist->slug,
            'status' => $artist->status,
        ];
    }

    private function buildUserPayload(User $user): array
    {
        $user->loadMissing(['artist', 'activeRoles.permissions']);

        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'full_name' => $user->full_name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'bio' => $user->bio ?? null,
            'country' => $user->country,
            'city' => $user->city ?? null,
            'role' => $user->role,
            'active_roles' => $user->activeRoles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'priority' => $role->priority,
                'permissions' => $role->permissionKeys(),
            ])->values()->all(),
            'permissions' => $user->getAllPermissions(),
            'event_organizer' => $user->getEventOrganizerProfile(),
            'is_active' => (bool) $user->is_active,
            'email_verified_at' => $user->email_verified_at,
            'last_login_at' => $user->last_login_at ?? null,
            'avatar_url' => $user->avatar
                ? url('storage/'.$user->avatar)
                : null,
            'artist' => $this->buildArtistReference($user),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    private function buildRoleHistoryItem(AuditLog $log): array
    {
        $roleName = $log->new_values['role'] ?? $log->old_values['role'] ?? null;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'role_name' => $roleName,
            'actor' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name ?: $log->user->username ?: $log->user->email,
                'email' => $log->user->email,
            ] : null,
            'before_roles' => array_values($log->old_values['roles'] ?? []),
            'after_roles' => array_values($log->new_values['roles'] ?? []),
            'expires_at' => $log->new_values['expires_at'] ?? null,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function generateUniqueArtistSlug(string $name, int $userId, ?int $ignoreArtistId = null): string
    {
        $baseSlug = \Illuminate\Support\Str::slug($name) ?: 'artist-'.$userId;
        $slug = $baseSlug;
        $suffix = 2;

        while (\App\Models\Artist::query()
            ->when($ignoreArtistId, fn ($query) => $query->where('id', '!=', $ignoreArtistId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function applyRole(User $user, string $roleName, ?int $assignedBy = null): void
    {
        $role = $this->resolveManagedRole($roleName);

        $managedRoleIds = Role::whereIn('name', self::MANAGED_ROLE_NAMES)->pluck('id');

        if ($managedRoleIds->isNotEmpty()) {
            DB::table('user_roles')
                ->where('user_id', $user->id)
                ->whereIn('role_id', $managedRoleIds)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }

        $existingPivot = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->exists();

        if ($existingPivot) {
            $user->roles()->updateExistingPivot($role->id, [
                'is_active' => true,
                'assigned_at' => now(),
                'assigned_by' => $assignedBy,
                'updated_at' => now(),
            ]);
        } else {
            $user->roles()->attach($role->id, [
                'is_active' => true,
                'assigned_at' => now(),
                'assigned_by' => $assignedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user->clearPermissionCache();
        $user->unsetRelation('roles');
        $user->unsetRelation('activeRoles');
    }

    /**
     * List users with filtering, searching, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $query = User::query()->with(['activeRoles.permissions']);
            $this->applyModeratorUserVisibility($request, $query);

            // Search
            if ($request->filled('search')) {
                $escaped = addcslashes($request->search, '%_');
                $query->where(function ($q) use ($escaped) {
                    $q->where('name', 'LIKE', "%{$escaped}%")
                        ->orWhere('username', 'LIKE', "%{$escaped}%")
                        ->orWhere('email', 'LIKE', "%{$escaped}%")
                        ->orWhere('full_name', 'LIKE', "%{$escaped}%");
                });
            }

            // Filter by role
            if ($request->filled('role') && $request->role !== 'all') {
                $query->whereHas('activeRoles', function ($roleQuery) use ($request) {
                    $roleQuery->where('name', $request->role);
                });
            }

            // Filter by status
            if ($request->filled('status') && $request->status !== 'all') {
                match ($request->status) {
                    'active' => $query->where('is_active', true),
                    'inactive', 'banned' => $query->where('is_active', false),
                    'verified' => $query->whereNotNull('email_verified_at'),
                    default => null,
                };
            }

            // Filter by active flag
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by country
            if ($request->filled('country')) {
                $query->where('country', $request->country);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $allowed = ['created_at', 'name', 'email', 'username', 'is_active'];
            if (in_array($sortBy, $allowed)) {
                $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
            } else {
                $query->latest();
            }

            $perPage = min((int) $request->get('per_page', 20), 100);
            $users = $query->paginate($perPage);

            // Transform for frontend compatibility
            $users->getCollection()->transform(function (User $user) {
                return [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->full_name ?: $user->username,
                    'username' => $user->username,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'country' => $user->country,
                    'role' => $user->role,
                    'active_roles' => $user->activeRoles->map(fn (Role $role) => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                        'priority' => $role->priority,
                        'permissions' => $role->permissionKeys(),
                    ])->values()->all(),
                    'permissions' => $user->getAllPermissions(),
                    'is_active' => (bool) $user->is_active,
                    'email_verified_at' => $user->email_verified_at,
                    'last_login_at' => $user->last_login_at ?? null,
                    'avatar_url' => $user->avatar
                        ? url('storage/'.$user->avatar)
                        : null,
                    'created_at' => $user->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $users->items(),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'total' => $users->total(),
                    'per_page' => $users->perPage(),
                    'last_page' => $users->lastPage(),
                ],
            ]);
        }, 'Failed to load users.');
    }

    /**
     * Get user statistics for the admin dashboard.
     */
    public function statistics(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $baseQuery = User::query();
            $this->applyModeratorUserVisibility($request, $baseQuery);
            $visibleUserIds = (clone $baseQuery)->select('users.id');

            $stats = [
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('is_active', true)->count(),
                'inactive' => (clone $baseQuery)->where('is_active', false)->count(),
                'verified' => (clone $baseQuery)->whereNotNull('email_verified_at')->count(),
                'new_this_month' => (clone $baseQuery)->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                'users_by_role' => DB::table('roles')
                    ->leftJoin('user_roles', function ($join) {
                        $join->on('roles.id', '=', 'user_roles.role_id')
                            ->where('user_roles.is_active', true);
                    })
                    ->when($request->user()?->isModeratorOnly(), function ($query) use ($visibleUserIds) {
                        $query
                            ->whereNotIn('roles.name', self::PRIVILEGED_ROLE_NAMES)
                            ->whereIn('user_roles.user_id', $visibleUserIds);
                    })
                    ->selectRaw('roles.name, count(user_roles.user_id) as count')
                    ->groupBy('roles.name')
                    ->pluck('count', 'name'),
                'users_by_country' => (clone $baseQuery)->selectRaw('country, count(*) as count')
                    ->whereNotNull('country')
                    ->groupBy('country')
                    ->orderByDesc('count')
                    ->limit(10)
                    ->pluck('count', 'country'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        }, 'Failed to load user statistics.');
    }

    /**
     * Show a single user with detailed information.
     */
    public function show(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::with('artist')->findOrFail($id);

            if ($response = $this->moderatorHiddenUserResponse($request, $user)) {
                return $response;
            }

            return response()->json([
                'success' => true,
                'data' => $this->buildUserPayload($user),
            ]);
        }, 'Failed to load user details.');
    }

    /**
     * Show access summary and role change history for a single user.
     */
    public function accessHistory(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::with(['artist', 'activeRoles.permissions'])->findOrFail($id);
            $currentUser = $request->user();

            if ($response = $this->moderatorHiddenUserResponse($request, $user)) {
                return $response;
            }

            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to view this user access history.',
                ], 403);
            }

            $history = AuditLog::query()
                ->with('user:id,name,username,email')
                ->whereIn('action', ['role_assigned', 'role_removed'])
                ->where(function ($query) use ($user) {
                    $query
                        ->where(function ($auditQuery) use ($user) {
                            $auditQuery
                                ->where('auditable_type', User::class)
                                ->where('auditable_id', $user->id);
                        })
                        ->orWhere('new_values->user_id', $user->id);
                })
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (AuditLog $log) => $this->buildRoleHistoryItem($log))
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $this->buildUserPayload($user),
                    'history' => $history,
                ],
            ]);
        }, 'Failed to load user access history.');
    }

    /**
     * Create a new user.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
                'username' => 'nullable|string|max:100|unique:users,username',
                'phone' => 'nullable|string|max:20',
                'role' => 'nullable|string|in:user,artist,moderator,admin',
                'country' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:255',
                'bio' => 'nullable|string|max:500',
                'is_active' => 'nullable|boolean',
                'email_verified' => 'nullable|boolean',
                'is_event_organizer' => 'nullable|boolean',
                'organizer_business_name' => 'nullable|string|max:255',
                'organizer_support_email' => 'nullable|email|max:255',
                'organizer_support_phone' => 'nullable|string|max:30',
                'organizer_notes' => 'nullable|string|max:2000',
                'organizer_payout_method' => 'nullable|string|in:mobile_money,bank_transfer',
                'organizer_mobile_money_provider' => 'nullable|string|max:50',
                'organizer_mobile_money_number' => 'nullable|string|max:50',
                'organizer_bank_name' => 'nullable|string|max:120',
                'organizer_bank_account' => 'nullable|string|max:120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $currentUser = $request->user();

            if ($currentUser->isModeratorOnly()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Moderators cannot create user accounts.',
                ], 403);
            }

            // Prevent non-super admins from creating admin users
            if (in_array($request->role, ['admin', 'super_admin']) && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to create admin users.',
                ], 403);
            }

            $user = DB::transaction(function () use ($request, $currentUser) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'username' => $request->username,
                    'password' => Hash::make($request->password),
                    'phone' => $request->phone,
                    'country' => $request->country ?? 'UG',
                    'city' => $request->city,
                    'bio' => $request->bio,
                    'is_active' => $request->boolean('is_active', true),
                    'email_verified_at' => $request->boolean('email_verified', true) ? now() : null,
                ]);

                $role = $request->role ?? 'user';
                $this->applyRole($user, $role, $currentUser->id);

                if ($role === 'artist') {
                    $user->update(['is_artist' => true]);
                    \App\Models\Artist::create([
                        'user_id' => $user->id,
                        'stage_name' => $user->display_name ?? $user->name ?? 'Artist',
                        'slug' => $this->generateUniqueArtistSlug(
                            $user->display_name ?? $user->name ?? 'Artist',
                            $user->id
                        ),
                        'status' => 'active',
                        'is_verified' => false,
                    ]);
                }

                $user->syncEventOrganizerProfile([
                    'enabled' => $request->boolean('is_event_organizer', false),
                    'business_name' => $request->input('organizer_business_name'),
                    'support_email' => $request->input('organizer_support_email'),
                    'support_phone' => $request->input('organizer_support_phone'),
                    'notes' => $request->input('organizer_notes'),
                    'payout_method' => $request->input('organizer_payout_method', 'mobile_money'),
                    'mobile_money_provider' => $request->input('organizer_mobile_money_provider'),
                    'mobile_money_number' => $request->input('organizer_mobile_money_number'),
                    'bank_name' => $request->input('organizer_bank_name'),
                    'bank_account' => $request->input('organizer_bank_account'),
                ]);

                return $user->fresh(['artist']);
            });

            return response()->json([
                'success' => true,
                'data' => $this->buildUserPayload($user),
                'message' => 'User created successfully.',
            ], 201);
        }, 'Failed to create user.');
    }

    /**
     * Update user details.
     */
    public function update(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::findOrFail($id);
            $currentUser = $request->user();

            if ($currentUser->isModeratorOnly()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Moderators cannot update user accounts.',
                ], 403);
            }

            // Permission check
            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to manage this user.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,'.$id,
                'username' => 'sometimes|string|max:100|unique:users,username,'.$id,
                'phone' => 'sometimes|string|max:20',
                'password' => 'sometimes|string|min:8',
                'role' => 'sometimes|string|in:user,artist,moderator,admin',
                'country' => 'nullable|string|max:2',
                'bio' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:255',
                'is_active' => 'nullable|boolean',
                'is_event_organizer' => 'nullable|boolean',
                'organizer_business_name' => 'nullable|string|max:255',
                'organizer_support_email' => 'nullable|email|max:255',
                'organizer_support_phone' => 'nullable|string|max:30',
                'organizer_notes' => 'nullable|string|max:2000',
                'organizer_payout_method' => 'nullable|string|in:mobile_money,bank_transfer',
                'organizer_mobile_money_provider' => 'nullable|string|max:50',
                'organizer_mobile_money_number' => 'nullable|string|max:50',
                'organizer_bank_name' => 'nullable|string|max:120',
                'organizer_bank_account' => 'nullable|string|max:120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Prevent non-super admins from assigning admin role
            if ($request->filled('role') && in_array($request->role, ['admin', 'super_admin']) && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to assign admin roles.',
                ], 403);
            }

            $updatedUser = DB::transaction(function () use ($request, $user, $currentUser) {
                $data = $request->only(['name', 'email', 'username', 'phone', 'country', 'bio', 'city']);

                if ($request->has('is_active')) {
                    $data['is_active'] = $request->boolean('is_active');
                }

                if ($request->filled('password')) {
                    $data['password'] = Hash::make($request->password);
                }

                $user->update(array_filter($data, fn ($v) => $v !== null));

                if ($request->filled('role')) {
                    $newRole = $request->input('role');
                    $oldRole = $user->role;

                    if ($newRole !== $oldRole) {
                        $this->applyRole($user, $newRole, $currentUser->id);

                        if ($newRole === 'artist') {
                            $user->update(['is_artist' => true]);
                            $artist = $user->artist;
                            if (! $artist) {
                                \App\Models\Artist::create([
                                    'user_id' => $user->id,
                                    'stage_name' => $user->display_name ?? $user->name ?? $user->username ?? 'Artist',
                                    'slug' => $this->generateUniqueArtistSlug(
                                        $user->display_name ?? $user->name ?? $user->username ?? 'Artist',
                                        $user->id
                                    ),
                                    'status' => 'active',
                                    'is_verified' => false,
                                ]);
                            }
                        }

                        if ($oldRole === 'artist' && $newRole !== 'artist') {
                            $user->update(['is_artist' => false]);
                        }
                    }
                }

                if ($request->has('is_event_organizer')) {
                    $user->syncEventOrganizerProfile([
                        'enabled' => $request->boolean('is_event_organizer'),
                        'business_name' => $request->input('organizer_business_name'),
                        'support_email' => $request->input('organizer_support_email'),
                        'support_phone' => $request->input('organizer_support_phone'),
                        'notes' => $request->input('organizer_notes'),
                        'payout_method' => $request->input('organizer_payout_method', 'mobile_money'),
                        'mobile_money_provider' => $request->input('organizer_mobile_money_provider'),
                        'mobile_money_number' => $request->input('organizer_mobile_money_number'),
                        'bank_name' => $request->input('organizer_bank_name'),
                        'bank_account' => $request->input('organizer_bank_account'),
                    ]);
                }

                return $user->fresh(['artist']);
            });

            return response()->json([
                'success' => true,
                'data' => $this->buildUserPayload($updatedUser),
                'message' => 'User updated successfully.',
            ]);
        }, 'Failed to update user.');
    }

    /**
     * Activate a user.
     */
    public function activate(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::findOrFail($id);
            $currentUser = $request->user();

            if ($currentUser->isModeratorOnly()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Moderators cannot activate user accounts.',
                ], 403);
            }

            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to manage this user.',
                ], 403);
            }

            $user->activate();

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully.',
            ]);
        }, 'Failed to activate user.');
    }

    /**
     * Ban a user.
     */
    public function ban(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::findOrFail($id);
            $currentUser = $request->user();

            if ($currentUser->isModeratorOnly()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Moderators cannot ban user accounts.',
                ], 403);
            }

            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to manage this user.',
                ], 403);
            }

            if ($user->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot ban super admin user.',
                ], 403);
            }

            $user->ban();

            return response()->json([
                'success' => true,
                'message' => 'User banned successfully.',
            ]);
        }, 'Failed to ban user.');
    }

    /**
     * Delete / deactivate a user.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $user = User::findOrFail($id);
            $currentUser = $request->user();

            if ($currentUser->isModeratorOnly()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Moderators cannot deactivate user accounts.',
                ], 403);
            }

            if (! $currentUser->canManageUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to manage this user.',
                ], 403);
            }

            if ($user->isSuperAdmin() && ! $currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete super admin user.',
                ], 403);
            }

            $user->deactivate();
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully.',
            ]);
        }, 'Failed to deactivate user.');
    }
}
