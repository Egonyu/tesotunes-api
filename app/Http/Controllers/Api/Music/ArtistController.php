<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\SongResource;
use App\Models\Artist;
use App\Models\UserFollow;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ArtistController extends Controller
{
    /**
     * GET /api/artists
     * Paginated list of active artists.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $artists = Artist::with('primaryGenre')
            ->where('status', 'active')
            ->when($request->filled('verified_only'), fn ($q) => $q->where('is_verified', $request->boolean('verified_only')))
            ->when($request->filled('country'), fn ($q) => $q->where('country', $request->country))
            ->when($request->filled('genre'), fn ($q) => $q->where('primary_genre_id', $request->genre))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('stage_name', 'like', '%' . $request->search . '%');
            })
            ->orderByDesc('followers_count')
            ->paginate($perPage);

        return ArtistResource::collection($artists);
    }

    /**
     * GET /api/artists/{artist}
     * Single artist by ID, slug, or UUID.
     */
    public function show(string $artist)
    {
        $record = Artist::with(['primaryGenre'])
            ->where('status', 'active')
            ->where(function ($q) use ($artist) {
                $q->where('id', $artist)
                  ->orWhere('slug', $artist)
                  ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        return new ArtistResource($record);
    }

    /**
     * GET /api/artists/{artist}/songs
     * Paginated songs for an artist.
     */
    public function songs(string $artist, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $record = Artist::where('status', 'active')
            ->where(function ($q) use ($artist) {
                $q->where('id', $artist)
                  ->orWhere('slug', $artist)
                  ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        $songs = $record->songs()
            ->with(['artist', 'album', 'primaryGenre'])
            ->where('status', 'published')
            ->orderByDesc('play_count')
            ->paginate($perPage);

        return SongResource::collection($songs);
    }

    /**
     * GET /api/artists/{artist}/albums
     * Paginated albums for an artist.
     */
    public function albums(string $artist, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);

        $record = Artist::where('status', 'active')
            ->where(function ($q) use ($artist) {
                $q->where('id', $artist)
                  ->orWhere('slug', $artist)
                  ->orWhere('uuid', $artist);
            })
            ->firstOrFail();

        $albums = $record->albums()
            ->with(['artist', 'primaryGenre'])
            ->where('status', 'published')
            ->orderByDesc('release_date')
            ->paginate($perPage);

        return AlbumResource::collection($albums);
    }

    public function toggleFollow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            $isFollowing = $user->following()
                ->where('following_id', $artist->id)
                ->where('following_type', 'artist')
                ->first();

            if ($isFollowing) {
                $isFollowing->delete();
                $artist->decrement('follower_count');
                $message = 'Artist unfollowed';
                $following = false;
            } else {
                $user->following()->create([
                    'following_id' => $artist->id,
                    'type' => 'artist',
                ]);
                $artist->increment('follower_count');
                $message = 'Artist followed';
                $following = true;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'is_following' => $following,
                'follower_count' => $artist->fresh()->follower_count
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist toggle follow error: ' . $e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle follow',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Follow an artist
     */
    public function follow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            $isAlreadyFollowing = $user->following()
                ->where('following_id', $artist->id)
                ->where('following_type', 'artist')
                ->exists();

            if ($isAlreadyFollowing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already following this artist'
                ], 400);
            }

            $user->following()->create([
                'following_id' => $artist->id,
                'type' => 'artist',
            ]);

            $artist->increment('follower_count');

            return response()->json([
                'success' => true,
                'message' => 'Artist followed successfully',
                'is_following' => true,
                'follower_count' => $artist->fresh()->follower_count
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist follow error: ' . $e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to follow artist',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Unfollow an artist
     */
    public function unfollow(Artist $artist): JsonResponse
    {
        try {
            $user = auth()->user();

            $deleted = $user->following()
                ->where('following_id', $artist->id)
                ->where('following_type', 'artist')
                ->delete();

            if ($deleted) {
                $artist->decrement('follower_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Artist unfollowed successfully',
                'is_following' => false,
                'follower_count' => $artist->fresh()->follower_count
            ]);

        } catch (\Exception $e) {
            \Log::error('Artist unfollow error: ' . $e->getMessage(), [
                'artist_id' => $artist->id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unfollow artist',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
