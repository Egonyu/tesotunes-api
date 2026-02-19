<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\SongResource;
use App\Models\Playlist;
use App\Models\PlaylistSong;
use App\Models\Song;
use App\Models\UserFollow as Follow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlaylistController extends Controller
{
    /**
     * List public playlists (paginated).
     *
     * GET /api/playlists
     */
    public function index(Request $request)
    {
        $query = Playlist::with(['owner'])
            ->where('visibility', 'public')
            ->withCount('songs');

        // Filters
        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        if ($request->has('collaborative')) {
            $query->where('is_collaborative', $request->boolean('collaborative'));
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        match ($sortBy) {
            'popularity' => $query->orderBy('follower_count', $sortOrder),
            'songs' => $query->orderBy('songs_count', $sortOrder),
            default => $query->orderBy($sortBy, $sortOrder),
        };

        $playlists = $query->paginate($request->integer('per_page', 20));

        return PlaylistResource::collection($playlists);
    }

    /**
     * Get current user's playlists (paginated).
     *
     * GET /api/playlists/mine | GET /api/my/playlists
     */
    public function myPlaylists(Request $request)
    {
        $playlists = Playlist::where('user_id', auth()->id())
            ->with(['songs.artist'])
            ->withCount('songs')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return PlaylistResource::collection($playlists);
    }

    /**
     * Get featured playlists.
     *
     * GET /api/playlists/featured
     */
    public function featured(Request $request)
    {
        $playlists = Playlist::with(['owner'])
            ->where('is_featured', true)
            ->where('visibility', 'public')
            ->withCount('songs')
            ->orderByDesc('follower_count')
            ->limit($request->integer('limit', 10))
            ->get();

        return PlaylistResource::collection($playlists);
    }

    /**
     * Create a new playlist.
     *
     * POST /api/playlists
     */
    public function store(Request $request): JsonResponse
    {
        // Accept both 'title' and 'name' for flexibility
        $playlistName = $request->input('title') ?? $request->input('name');

        $validator = Validator::make(array_merge($request->all(), ['playlist_name' => $playlistName]), [
            'playlist_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'boolean',
            'is_collaborative' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $visibility = $request->boolean('is_public', true) ? 'public' : 'private';

        $playlist = Playlist::create([
            'user_id' => auth()->id(),
            'name' => $playlistName,
            'description' => $request->description,
            'visibility' => $visibility,
            'is_collaborative' => $request->boolean('is_collaborative', false),
        ]);

        return (new PlaylistResource($playlist->load('owner')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single playlist.
     *
     * GET /api/playlists/{playlist}
     */
    public function show(Playlist $playlist)
    {
        // Check visibility
        if ($playlist->visibility !== 'public' && (! auth()->check() || $playlist->user_id !== auth()->id())) {
            abort(404, 'Playlist not found');
        }

        $playlist->load([
            'owner',
            'songs.artist',
            'songs.album',
        ])->loadCount('songs');

        return new PlaylistResource($playlist);
    }

    /**
     * Get tracks in a playlist (paginated).
     *
     * GET /api/playlists/{id}/tracks
     */
    public function tracks(Request $request, Playlist $playlist)
    {
        if ($playlist->visibility !== 'public' && (! auth()->check() || $playlist->user_id !== auth()->id())) {
            abort(404, 'Playlist not found');
        }

        $songs = $playlist->songs()
            ->with(['artist', 'album'])
            ->published()
            ->paginate($request->integer('per_page', 20));

        return SongResource::collection($songs);
    }

    /**
     * Update a playlist.
     *
     * PUT /api/playlists/{playlist}
     */
    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'boolean',
            'is_collaborative' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['description', 'is_collaborative']);

        // Handle name/title
        if ($request->has('title')) {
            $data['name'] = $request->input('title');
        } elseif ($request->has('name')) {
            $data['name'] = $request->input('name');
        }

        // Handle visibility
        if ($request->has('is_public')) {
            $data['visibility'] = $request->boolean('is_public') ? 'public' : 'private';
        }

        $playlist->update($data);

        return (new PlaylistResource($playlist->fresh()->load('owner')))->response();
    }

    /**
     * Delete a playlist.
     *
     * DELETE /api/playlists/{playlist}
     */
    public function destroy(Playlist $playlist): JsonResponse
    {
        if ($playlist->user_id !== auth()->id()) {
            abort(403, 'You are not authorized to delete this playlist');
        }

        $playlist->delete();

        return response()->json(['message' => 'Playlist deleted successfully']);
    }

    /**
     * Add song to playlist (song ID in URL).
     *
     * POST /api/playlists/{playlist}/songs/{song}
     */
    public function addSong(Request $request, Playlist $playlist, Song $song): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        if (PlaylistSong::where('playlist_id', $playlist->id)->where('song_id', $song->id)->exists()) {
            return response()->json(['message' => 'Song already exists in playlist'], 409);
        }

        $playlist->addSong($song, auth()->user());

        return response()->json([
            'message' => 'Song added to playlist',
            'data' => [
                'playlist_id' => $playlist->id,
                'song_id' => $song->id,
                'song_count' => $playlist->fresh()->song_count,
            ],
        ], 201);
    }

    /**
     * Add song to playlist (song ID in request body).
     *
     * POST /api/playlists/{playlist}/tracks
     */
    public function addSongFromBody(Request $request, Playlist $playlist): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $songId = $request->input('track_id') ?? $request->input('song_id');

        if (! $songId) {
            return response()->json(['message' => 'No track_id or song_id provided'], 422);
        }

        $song = Song::findOrFail($songId);

        if (PlaylistSong::where('playlist_id', $playlist->id)->where('song_id', $song->id)->exists()) {
            return response()->json(['message' => 'Song already exists in playlist'], 409);
        }

        $playlist->addSong($song, auth()->user());

        return response()->json([
            'message' => 'Song added to playlist',
            'data' => [
                'playlist_id' => $playlist->id,
                'song_id' => $song->id,
                'song_count' => $playlist->fresh()->song_count,
            ],
        ], 201);
    }

    /**
     * Remove a song from a playlist.
     *
     * DELETE /api/playlists/{playlist}/songs/{song}
     */
    public function removeSong(Playlist $playlist, Song $song): JsonResponse
    {
        if (! $playlist->canBeEditedBy(auth()->user())) {
            abort(403, 'You are not authorized to edit this playlist');
        }

        $playlist->removeSong($song);

        return response()->json(['message' => 'Song removed from playlist']);
    }

    /**
     * Follow or unfollow a playlist.
     *
     * POST /api/playlists/{playlist}/follow
     */
    public function toggleFollow(Playlist $playlist): JsonResponse
    {
        $user = auth()->user();

        if ($playlist->user_id === $user->id) {
            return response()->json(['message' => 'You cannot follow your own playlist'], 400);
        }

        $existing = Follow::where('user_id', $user->id)
            ->where('followable_type', Playlist::class)
            ->where('followable_id', $playlist->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $playlist->decrement('follower_count');
            $following = false;
        } else {
            Follow::create([
                'user_id' => $user->id,
                'followable_type' => Playlist::class,
                'followable_id' => $playlist->id,
            ]);
            $playlist->increment('follower_count');
            $following = true;
        }

        return response()->json([
            'data' => [
                'is_following' => $following,
                'follower_count' => $playlist->fresh()->follower_count,
            ],
        ]);
    }
}
