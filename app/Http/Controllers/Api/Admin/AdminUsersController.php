<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    /**
     * List users with filtering, searching, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $query = User::query();

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
            $query->where('role', $request->role);
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
        $allowed = ['created_at', 'name', 'email', 'username', 'role', 'is_active'];
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
    public function statistics(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $stats = [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
                'verified' => User::whereNotNull('email_verified_at')->count(),
                'new_this_month' => User::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                'users_by_role' => User::selectRaw('role, count(*) as count')
                    ->groupBy('role')
                    ->pluck('count', 'role'),
                'users_by_country' => User::selectRaw('country, count(*) as count')
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
    public function show($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $user = User::findOrFail($id);

        $data = [
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
            'is_active' => (bool) $user->is_active,
            'email_verified_at' => $user->email_verified_at,
            'last_login_at' => $user->last_login_at ?? null,
            'avatar_url' => $user->avatar
                ? url('storage/'.$user->avatar)
                : null,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to load user details.');
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
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|string|in:user,artist,moderator,admin',
            'country' => 'nullable|string|max:2',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentUser = $request->user();

        // Prevent non-super admins from creating admin users
        if (in_array($request->role, ['admin', 'super_admin']) && ! $currentUser->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to create admin users.',
            ], 403);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role ?? 'user',
            'country' => $request->country ?? 'UG',
            'is_active' => $request->boolean('is_active', true),
            'email_verified_at' => now(),
        ]);

            return response()->json([
                'success' => true,
                'data' => $user,
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

        $data = $request->only(['name', 'email', 'username', 'phone', 'role', 'country', 'bio', 'city']);

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update(array_filter($data, fn ($v) => $v !== null));

            return response()->json([
                'success' => true,
                'data' => $user->fresh(),
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

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully.',
            ]);
        }, 'Failed to deactivate user.');
    }
}
