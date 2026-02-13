<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminArtistsController extends Controller
{
    /**
     * Get all artists for admin panel
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 12);
        $status = $request->get('status');
        $search = $request->get('search');
        
        $query = DB::table('artists')
            ->select([
                'artists.id',
                'artists.uuid',
                'artists.stage_name as name',
                'artists.slug',
                'artists.avatar',
                'artists.status',
                'artists.is_verified',
                'artists.total_songs as songs_count',
                'artists.total_albums as albums_count',
                'artists.follower_count as followers_count',
                'artists.total_plays',
                'artists.created_at',
                'users.email',
                'users.username'
            ])
            ->join('users', 'artists.user_id', '=', 'users.id');
        
        if ($status && $status !== 'all') {
            $query->where('artists.status', $status);
        }
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('artists.stage_name', 'LIKE', "%{$search}%")
                  ->orWhere('users.email', 'LIKE', "%{$search}%")
                  ->orWhere('users.username', 'LIKE', "%{$search}%");
            });
        }
        
        $artists = $query->orderBy('artists.created_at', 'desc')->paginate($perPage);
        
        // Transform data to include full URLs
        $data = collect($artists->items())->map(function ($artist) {
            $artist->avatar_url = $artist->avatar 
                ? url('storage/' . $artist->avatar) 
                : null;
            return $artist;
        })->toArray();
        
        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $artists->currentPage(),
                'total' => $artists->total(),
                'per_page' => $artists->perPage(),
                'last_page' => $artists->lastPage(),
            ],
        ]);
    }

    /**
     * Get artist statistics for admin
     */
    public function statistics()
    {
        $stats = [
            'total' => DB::table('artists')->count(),
            'verified' => DB::table('artists')->where('is_verified', 1)->count(),
            'pending_verification' => DB::table('artists')->where('is_verified', 0)->where('status', 'active')->count(),
            'new_this_month' => DB::table('artists')
                ->whereMonth('created_at', date('m'))
                ->whereYear('created_at', date('Y'))
                ->count(),
        ];
        
        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get single artist details for admin
     */
    public function show($id)
    {
        $artist = DB::table('artists')
            ->select([
                'artists.*',
                'users.email',
                'users.username',
                'users.phone',
                'users.full_name'
            ])
            ->join('users', 'artists.user_id', '=', 'users.id')
            ->where('artists.id', $id)
            ->first();
        
        if (!$artist) {
            return response()->json([
                'message' => 'Artist not found.',
            ], 404);
        }
        
        // Add full URLs
        $artist->avatar_url = $artist->avatar 
            ? url('storage/' . $artist->avatar) 
            : null;
        $artist->banner_url = $artist->banner 
            ? url('storage/' . $artist->banner) 
            : null;
        
        // Get artist's songs count
        $artist->songs = DB::table('songs')
            ->where('artist_id', $id)
            ->count();
        
        // Get artist's albums count
        $artist->albums = DB::table('albums')
            ->where('artist_id', $id)
            ->count();
        
        return response()->json([
            'data' => $artist,
        ]);
    }

    /**
     * Verify an artist
     */
    public function verify($id)
    {
        DB::table('artists')
            ->where('id', $id)
            ->update([
                'is_verified' => 1,
                'verified_at' => now(),
                'verified_by' => 1, // Admin user ID
                'updated_at' => now(),
            ]);
        
        return response()->json([
            'message' => 'Artist verified successfully.',
        ]);
    }

    /**
     * Update artist status
     */
    public function updateStatus(Request $request, $id)
    {
        $status = $request->input('status');
        
        if (!in_array($status, ['active', 'pending', 'suspended', 'rejected'])) {
            return response()->json([
                'message' => 'Invalid status.',
            ], 422);
        }
        
        DB::table('artists')
            ->where('id', $id)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
        
        return response()->json([
            'message' => 'Artist status updated successfully.',
        ]);
    }

    /**
     * Delete an artist
     */
    public function destroy($id)
    {
        DB::table('artists')
            ->where('id', $id)
            ->delete();
        
        return response()->json(null, 204);
    }

    /**
     * Update artist
     */
    public function update(Request $request, $id)
    {
        $data = [
            'stage_name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'bio' => $request->input('bio'),
            'country' => $request->input('country'),
            'city' => $request->input('city'),
            'updated_at' => now(),
        ];
        
        DB::table('artists')
            ->where('id', $id)
            ->update($data);
        
        return response()->json([
            'message' => 'Artist updated successfully.',
        ]);
    }

    /**
     * Toggle featured status
     */
    public function toggleFeatured($id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();
        
        if (!$artist) {
            return response()->json([
                'message' => 'Artist not found.',
            ], 404);
        }
        
        $isFeatured = $artist->verification_badge === 'featured';
        
        DB::table('artists')
            ->where('id', $id)
            ->update([
                'verification_badge' => $isFeatured ? 'verified' : 'featured',
                'updated_at' => now(),
            ]);
        
        return response()->json([
            'message' => $isFeatured ? 'Artist unfeatured.' : 'Artist featured.',
        ]);
    }

    /**
     * Toggle verify status
     */
    public function toggleVerify($id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();
        
        if (!$artist) {
            return response()->json([
                'message' => 'Artist not found.',
            ], 404);
        }
        
        $isVerified = $artist->is_verified == 1;
        
        DB::table('artists')
            ->where('id', $id)
            ->update([
                'is_verified' => $isVerified ? 0 : 1,
                'verified_at' => $isVerified ? null : now(),
                'verified_by' => $isVerified ? null : 1,
                'updated_at' => now(),
            ]);
        
        return response()->json([
            'message' => $isVerified ? 'Artist unverified.' : 'Artist verified.',
        ]);
    }
}
