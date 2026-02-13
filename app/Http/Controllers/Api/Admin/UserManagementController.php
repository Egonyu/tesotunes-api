<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'admin']);
    }

    /**
     * Get users with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = User::with(['activeRoles', 'settings']);

            // Apply filters
            if ($request->filled('role')) {
                $query->whereHas('roles', function($q) use ($request) {
                    $q->where('name', $request->role);
                });
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->filled('country')) {
                $query->where('country', $request->country);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginate results
            $perPage = min($request->get('per_page', 20), 100);
            $users = $query->paginate($perPage);

            // Transform user data
            $users->getCollection()->transform(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->roles->pluck('display_name')->join(', ') ?: 'User',
                    'is_active' => $user->is_active,
                    'country' => $user->country,
                    'last_login_at' => $user->last_login_at,
                    'created_at' => $user->created_at,
                    'active_roles' => $user->activeRoles->pluck('name'),
                    'avatar_url' => $user->avatar_url,
                    'is_online' => $user->is_online,
                ];
            });

            return response()->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user details
     */
    public function show(User $user): JsonResponse
    {
        try {
            $user->load([
                'activeRoles',
                'settings',
                'playlists' => function ($query) {
                    $query->limit(5);
                },
                'activities' => function ($query) {
                    $query->latest()->limit(10);
                }
            ]);

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->roles->pluck('display_name')->join(', ') ?: 'User',
                    'is_active' => $user->is_active,
                    'permissions' => $user->getAllPermissions(),
                    'avatar' => $user->avatar,
                    'avatar_url' => $user->avatar_url,
                    'bio' => $user->bio,
                    'country' => $user->country,
                    'city' => $user->city,
                    'phone' => $user->phone,
                    'date_of_birth' => $user->date_of_birth,
                    'preferred_language' => $user->preferred_language,
                    'last_login_at' => $user->last_login_at,
                    'last_seen_at' => $user->last_seen_at,
                    'is_online' => $user->is_online,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'active_roles' => $user->activeRoles,
                    'settings' => $user->settings,
                    'playlists_count' => $user->playlists()->count(),
                    'followers_count' => $user->followers()->count(),
                    'following_count' => $user->following()->count(),
                    'recent_playlists' => $user->playlists,
                    'recent_activities' => $user->activities,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new user
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|string|in:user,artist,moderator,admin',
            'country' => 'nullable|string|max:2',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $currentUser = $request->user();

            // Prevent non-super admins from creating admin users
            if (in_array($request->role, ['admin', 'super_admin']) && !$currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to create admin users'
                ], 403);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'country' => $request->country ?? 'UG',
                'phone' => $request->phone,
                'is_active' => $request->is_active ?? true,
                'email_verified_at' => now(),
            ]);

            // Assign role
            $user->assignRole($request->role, $currentUser->id);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'user' => $user->load('activeRoles')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user details
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        // Check if current user can manage the target user
        if (!$currentUser->canManageUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to manage this user'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|in:user,artist,moderator,admin',
            'country' => 'nullable|string|max:2',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
            'bio' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Prevent non-super admins from modifying admin users
            if (in_array($request->role, ['admin', 'super_admin']) && !$currentUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions to assign admin roles'
                ], 403);
            }

            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'country' => $request->country,
                'phone' => $request->phone,
                'is_active' => $request->is_active ?? $user->is_active,
                'bio' => $request->bio,
                'city' => $request->city,
            ]);

            // Update role assignment if changed
            if ($request->filled('role')) {
                $currentRole = $user->roles->first()?->name;
                if ($currentRole !== $request->role) {
                    $user->assignRole($request->role, $currentUser->id);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'user' => $user->load('activeRoles')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete/Deactivate user
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        // Check if current user can manage the target user
        if (!$currentUser->canManageUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to manage this user'
            ], 403);
        }

        // Prevent deletion of super admin by non-super admins
        if ($user->isSuperAdmin() && !$currentUser->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete super admin user'
            ], 403);
        }

        try {
            // Soft delete by deactivating instead of hard delete
            $user->deactivate();

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate user
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->canManageUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to manage this user'
            ], 403);
        }

        try {
            $user->activate();

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ban user
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->canManageUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to manage this user'
            ], 403);
        }

        if ($user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot ban super admin user'
            ], 403);
        }

        try {
            $user->ban();

            return response()->json([
                'success' => true,
                'message' => 'User banned successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to ban user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'inactive_users' => User::where('is_active', false)->count(),
                'users_by_role' => User::select('role')
                                      ->selectRaw('count(*) as count')
                                      ->groupBy('role')
                                      ->pluck('count', 'role'),
                'users_by_country' => User::select('country')
                                          ->selectRaw('count(*) as count')
                                          ->whereNotNull('country')
                                          ->groupBy('country')
                                          ->orderBy('count', 'desc')
                                          ->limit(10)
                                          ->pluck('count', 'country'),
                'new_users_this_month' => User::whereMonth('created_at', now()->month)
                                              ->whereYear('created_at', now()->year)
                                              ->count(),
                'online_users' => User::where('is_online', true)->count(),
            ];

            return response()->json([
                'success' => true,
                'statistics' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}