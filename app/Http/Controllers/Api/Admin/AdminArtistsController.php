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
                'artists.total_songs_count as songs_count',
                'artists.total_albums_count as albums_count',
                'artists.followers_count',
                'artists.total_plays_count as total_plays',
                'artists.created_at',
                'users.email',
                'users.username',
            ])
            ->join('users', 'artists.user_id', '=', 'users.id');

        if ($status && $status !== 'all') {
            $query->where('artists.status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('artists.stage_name', 'LIKE', "%{$search}%")
                    ->orWhere('users.email', 'LIKE', "%{$search}%")
                    ->orWhere('users.username', 'LIKE', "%{$search}%");
            });
        }

        $artists = $query->orderBy('artists.created_at', 'desc')->paginate($perPage);

        // Transform data to include full URLs
        $data = collect($artists->items())->map(function ($artist) {
            $artist->avatar_url = $artist->avatar
                ? url('storage/'.$artist->avatar)
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
                'artists.id',
                'artists.uuid',
                'artists.user_id',
                'artists.stage_name as name',
                'artists.slug',
                'artists.bio',
                'artists.avatar',
                'artists.cover_image',
                'artists.status',
                'artists.is_verified',
                'artists.is_trusted',
                'artists.verification_status',
                'artists.verified_at',
                'artists.website_url',
                'artists.social_links',
                'artists.primary_genre_id',
                'artists.total_songs_count',
                'artists.total_albums_count',
                'artists.total_plays_count',
                'artists.followers_count',
                'artists.earnings_balance',
                'artists.commission_rate',
                'artists.can_upload',
                'artists.auto_publish',
                'artists.require_approval',
                'artists.distribution_suspended',
                'artists.record_label',
                'artists.career_start_year',
                'artists.influences',
                'artists.created_at',
                'artists.updated_at',
                'users.id as user_table_id',
                'users.email',
                'users.username',
                'users.phone',
                'users.full_name',
                'users.name as user_name',
            ])
            ->join('users', 'artists.user_id', '=', 'users.id')
            ->where('artists.id', $id)
            ->first();

        if (! $artist) {
            return response()->json([
                'message' => 'Artist not found.',
            ], 404);
        }

        // Add full URLs
        $artist->avatar_url = $artist->avatar
            ? url('storage/'.$artist->avatar)
            : null;
        $artist->cover_url = $artist->cover_image
            ? url('storage/'.$artist->cover_image)
            : null;

        // Parse social_links JSON
        $socialLinks = json_decode($artist->social_links, true) ?? [];
        $artist->spotify_url = $socialLinks['spotify'] ?? null;
        $artist->apple_music_url = $socialLinks['apple_music'] ?? null;
        $artist->youtube_url = $socialLinks['youtube'] ?? null;
        $artist->instagram_url = $socialLinks['instagram'] ?? null;
        $artist->twitter_url = $socialLinks['twitter'] ?? null;
        $artist->facebook_url = $socialLinks['facebook'] ?? null;
        $artist->tiktok_url = $socialLinks['tiktok'] ?? null;
        $artist->website = $artist->website_url;

        // Alias counts for frontend
        $artist->total_songs = $artist->total_songs_count ?? 0;
        $artist->total_albums = $artist->total_albums_count ?? 0;
        $artist->total_plays = $artist->total_plays_count ?? 0;
        $artist->followers = $artist->followers_count ?? 0;
        $artist->is_featured = (bool) $artist->is_trusted;
        $artist->profile_url = $artist->avatar_url;

        // Get primary genre
        $artist->genres = [];
        if ($artist->primary_genre_id) {
            $genre = DB::table('genres')->where('id', $artist->primary_genre_id)->first();
            if ($genre) {
                $artist->genres = [['id' => (string) $genre->id, 'name' => $genre->name]];
            }
        }

        // Get top songs
        $artist->top_songs = DB::table('songs')
            ->where('artist_id', $id)
            ->select('id', 'title', 'slug', 'play_count as plays', 'artwork as cover_url')
            ->orderBy('play_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($song) {
                $song->cover_url = $song->cover_url ? url('storage/'.$song->cover_url) : null;

                return $song;
            })
            ->toArray();

        // Get recent albums
        $artist->recent_albums = DB::table('albums')
            ->where('artist_id', $id)
            ->select('id', 'title', 'slug', 'artwork as cover_url', 'release_date', 'album_type')
            ->orderBy('created_at', 'desc')
            ->limit(4)
            ->get()
            ->map(function ($album) {
                $album->cover_url = $album->cover_url ? url('storage/'.$album->cover_url) : null;

                return $album;
            })
            ->toArray();

        // User profile info
        $artist->user = [
            'id' => $artist->user_table_id,
            'name' => $artist->user_name ?? $artist->full_name ?? '',
            'email' => $artist->email,
            'username' => $artist->username,
            'phone' => $artist->phone,
        ];

        return response()->json([
            'data' => $artist,
        ]);
    }

    /**
     * Verify an artist
     */
    public function verify($id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();
        if (! $artist) {
            return response()->json(['message' => 'Artist not found.'], 404);
        }

        DB::table('artists')
            ->where('id', $id)
            ->update([
                'is_verified' => 1,
                'verification_status' => 'approved',
                'verified_at' => now(),
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

        if (! in_array($status, ['active', 'pending', 'suspended', 'rejected'])) {
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
        $artist = DB::table('artists')->where('id', $id)->first();
        if (! $artist) {
            return response()->json(['message' => 'Artist not found.'], 404);
        }

        // Soft-delete: mark as deleted rather than hard remove
        DB::table('artists')
            ->where('id', $id)
            ->update([
                'status' => 'suspended',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Artist deleted successfully.']);
    }

    /**
     * Update artist
     */
    public function update(Request $request, $id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();
        if (! $artist) {
            return response()->json(['message' => 'Artist not found.'], 404);
        }

        $data = [
            'updated_at' => now(),
        ];

        // Basic fields
        if ($request->has('name')) {
            $data['stage_name'] = $request->input('name');
        }
        if ($request->has('slug')) {
            $data['slug'] = $request->input('slug');
        }
        if ($request->has('bio')) {
            $data['bio'] = $request->input('bio');
        }
        if ($request->has('website')) {
            $data['website_url'] = $request->input('website');
        }
        if ($request->has('status')) {
            $data['status'] = $request->input('status');
        }
        if ($request->has('is_verified')) {
            $data['is_verified'] = (bool) $request->input('is_verified');
        }

        // Social links — merge into JSON
        $socialKeys = ['spotify_url', 'apple_music_url', 'youtube_url', 'instagram_url', 'twitter_url', 'facebook_url', 'tiktok_url'];
        $existingSocial = json_decode($artist->social_links, true) ?? [];
        $keyMap = [
            'spotify_url' => 'spotify',
            'apple_music_url' => 'apple_music',
            'youtube_url' => 'youtube',
            'instagram_url' => 'instagram',
            'twitter_url' => 'twitter',
            'facebook_url' => 'facebook',
            'tiktok_url' => 'tiktok',
        ];
        $socialChanged = false;
        foreach ($socialKeys as $key) {
            if ($request->has($key)) {
                $existingSocial[$keyMap[$key]] = $request->input($key);
                $socialChanged = true;
            }
        }
        if ($socialChanged) {
            $data['social_links'] = json_encode($existingSocial);
        }

        // Genre
        $genreIds = $request->input('genre_ids');
        if (is_array($genreIds) && count($genreIds) > 0) {
            $data['primary_genre_id'] = $genreIds[0];
        }

        // SEO — stored in dedicated table or ignored for now
        // meta_title and meta_description not in artists table

        // File uploads
        if ($request->hasFile('profile_image')) {
            $path = $request->file('profile_image')->store('artists/avatars', 'public');
            $data['avatar'] = $path;
        }
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('artists/covers', 'public');
            $data['cover_image'] = $path;
        }

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

        if (! $artist) {
            return response()->json([
                'message' => 'Artist not found.',
            ], 404);
        }

        // Use is_trusted field as featured flag
        $isFeatured = (bool) $artist->is_trusted;

        DB::table('artists')
            ->where('id', $id)
            ->update([
                'is_trusted' => $isFeatured ? 0 : 1,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => $isFeatured ? 'Artist unfeatured.' : 'Artist featured.',
            'is_featured' => ! $isFeatured,
        ]);
    }

    /**
     * Toggle verify status
     */
    public function toggleVerify($id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();

        if (! $artist) {
            return response()->json([
                'message' => 'Artist not found.',
            ], 404);
        }

        $isVerified = $artist->is_verified == 1;

        DB::table('artists')
            ->where('id', $id)
            ->update([
                'is_verified' => $isVerified ? 0 : 1,
                'verification_status' => $isVerified ? 'pending' : 'approved',
                'verified_at' => $isVerified ? null : now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => $isVerified ? 'Artist unverified.' : 'Artist verified.',
        ]);
    }

    /**
     * Approve a pending artist
     */
    public function approve($id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();
        if (! $artist) {
            return response()->json(['message' => 'Artist not found.'], 404);
        }

        DB::table('artists')
            ->where('id', $id)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Artist approved successfully.',
        ]);
    }

    /**
     * Suspend an artist
     */
    public function suspend($id)
    {
        $artist = DB::table('artists')->where('id', $id)->first();
        if (! $artist) {
            return response()->json(['message' => 'Artist not found.'], 404);
        }

        DB::table('artists')
            ->where('id', $id)
            ->update([
                'status' => 'suspended',
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Artist suspended successfully.',
        ]);
    }
}
