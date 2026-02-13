<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsersController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 12);
        $search = $request->get('search');
        $role = $request->get('role');
        
        $query = DB::table('users')
            ->select([
                'id',
                'uuid',
                'username',
                'email',
                'full_name',
                'avatar',
                'phone',
                'country',
                'is_active',
                'email_verified_at',
                'created_at'
            ]);
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('username', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%")
                  ->orWhere('full_name', 'LIKE', "%{$search}%");
            });
        }
        
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);
        
        $data = collect($users->items())->map(function ($user) {
            $user->avatar_url = $user->avatar 
                ? url('storage/' . $user->avatar) 
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
        
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        
        $user->avatar_url = $user->avatar 
            ? url('storage/' . $user->avatar) 
            : null;
        
        return response()->json([
            'data' => $user,
        ]);
    }
}
