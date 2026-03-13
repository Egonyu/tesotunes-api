<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\SongResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * GET /api/user/profile
     * Get the authenticated user's full profile.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        $user->load(['settings', 'subscription', 'artist']);

        $user->loadCount(['playlists', 'followers', 'following', 'downloads', 'likes']);

        return new UserResource($user);
    }

    /**
     * PUT /api/user
     * Update the authenticated user's profile.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'bio' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other,prefer_not_to_say',
            'avatar' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $updateData = collect($validated)->except(['avatar', 'cover_image'])->toArray();

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                StorageHelper::delete($user->avatar);
            }
            $updateData['avatar'] = StorageHelper::store($request->file('avatar'), 'avatars');
        }

        // Handle cover image upload
        if ($request->hasFile('cover_image')) {
            if ($user->banner) {
                StorageHelper::delete($user->banner);
            }
            $updateData['banner'] = StorageHelper::store($request->file('cover_image'), 'covers');
        }

        $user->update($updateData);

        return new UserResource($user->fresh()->load(['settings', 'subscription', 'artist']));
    }

    /**
     * GET /api/user/library
     * Aggregated library: liked songs, playlists, recent plays, downloads, followed artists.
     */
    public function library(Request $request)
    {
        $user = $request->user();

        // Liked songs (most recent first, limited)
        $likedSongs = $user->likedSongs()
            ->with(['artist', 'album', 'genre'])
            ->orderByPivot('liked_at', 'desc')
            ->limit(20)
            ->get();

        // User's playlists
        $playlists = $user->playlists()
            ->withCount('songs')
            ->latest()
            ->limit(20)
            ->get();

        // Recent play history
        $recentPlays = $user->playHistory()
            ->with(['song.artist', 'song.album'])
            ->latest()
            ->limit(20)
            ->get()
            ->pluck('song')
            ->filter()
            ->unique('id')
            ->values();

        // Downloads
        $downloads = $user->downloads()
            ->with(['song.artist', 'song.album'])
            ->latest()
            ->limit(20)
            ->get()
            ->pluck('song')
            ->filter()
            ->unique('id')
            ->values();

        // Followed artists
        $followedArtists = $user->followedArtists()
            ->with('artist')
            ->limit(20)
            ->get()
            ->filter(fn ($u) => $u->artist)
            ->values();

        return response()->json([
            'data' => [
                'liked_songs' => SongResource::collection($likedSongs),
                'playlists' => PlaylistResource::collection($playlists),
                'recent_plays' => SongResource::collection($recentPlays),
                'downloads' => SongResource::collection($downloads),
                'followed_artists' => $followedArtists->map(fn ($u) => [
                    'id' => $u->artist->id,
                    'name' => $u->artist->stage_name ?? $u->name,
                    'slug' => $u->artist->slug,
                    'avatar_url' => $u->avatar ? url('storage/'.$u->avatar) : null,
                    'is_verified' => (bool) $u->artist->is_verified,
                ]),
                'counts' => [
                    'liked_songs' => $user->likes()->forType(\App\Models\Song::class)->count(),
                    'playlists' => $user->playlists()->count(),
                    'downloads' => $user->downloads()->count(),
                    'followed_artists' => $user->followedArtists()->count(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/users/{user}
     * Get a public user profile.
     */
    public function getUserProfile(Request $request, User $user)
    {
        $currentUser = $request->user();

        // Check privacy settings
        if (! $user->settings?->profile_public && (! $currentUser || $user->id !== $currentUser->id)) {
            return response()->json(['message' => 'This profile is private'], 403);
        }

        $user->load([
            'settings',
            'playlists' => function ($query) use ($currentUser, $user) {
                $query->where('is_public', true);
                if ($currentUser && $user->id === $currentUser->id) {
                    $query->orWhere('user_id', $currentUser->id);
                }
                $query->latest()->limit(10);
            },
        ]);

        $user->loadCount([
            'followers',
            'following',
            'playlists' => fn ($q) => $q->where('is_public', true),
        ]);

        return new UserResource($user);
    }
}
