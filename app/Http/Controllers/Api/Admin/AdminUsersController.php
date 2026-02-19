<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminUsersController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $search = $request->get('search');
        $role = $request->get('role');
        $status = $request->get('status');

        $query = DB::table('users')
            ->select([
                'id',
                'uuid',
                'username',
                'email',
                'name',
                'full_name',
                'avatar',
                'phone',
                'country',
                'role',
                'is_active',
                'email_verified_at',
                'created_at',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('full_name', 'LIKE', "%{$search}%");
            });
        }

        if ($role && $role !== 'all') {
            $query->where('role', $role);
        }

        if ($status && $status !== 'all') {
            if ($status === 'active') {
                $query->where('is_active', 1);
            } elseif ($status === 'inactive' || $status === 'banned') {
                $query->where('is_active', 0);
            } elseif ($status === 'verified') {
                $query->whereNotNull('email_verified_at');
            }
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = collect($users->items())->map(function ($user) {
            $user->avatar_url = $user->avatar
                ? url('storage/'.$user->avatar)
                : null;
            // Add name field for frontend compatibility
            $user->name = $user->full_name ?: $user->username;

            return $user;
        })->toArray();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $users->currentPage(),
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function statistics()
    {
        $stats = [
            'total' => DB::table('users')->count(),
            'active' => DB::table('users')->where('is_active', 1)->count(),
            'verified' => DB::table('users')->whereNotNull('email_verified_at')->count(),
            'new_this_month' => DB::table('users')
                ->whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->count(),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    public function show($id)
    {
        $user = DB::table('users')
            ->where('id', $id)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $user->avatar_url = $user->avatar
            ? url('storage/'.$user->avatar)
            : null;

        return response()->json([
            'data' => $user,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|string',
            'is_artist' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role ?? 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return response()->json(['data' => $user, 'message' => 'User created successfully'], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$id,
            'username' => 'sometimes|string|max:100|unique:users,username,'.$id,
            'phone' => 'sometimes|string|max:20',
            'password' => 'sometimes|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'email', 'username', 'phone']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json(['data' => $user, 'message' => 'User updated successfully']);
    }

    public function ban($id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => false]);

        return response()->json(['message' => 'User has been banned']);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
