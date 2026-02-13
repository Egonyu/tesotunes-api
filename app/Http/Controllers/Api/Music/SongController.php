<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Services\SongService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SongController extends Controller
{
    protected SongService $songService;

    public function __construct(SongService $songService)
    {
        $this->songService = $songService;
    }

    /**
     * GET /api/songs
     * Paginated list of published songs.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $songs = Song::with(['artist', 'album', 'primaryGenre'])
            ->published()
            ->whereHas('artist', fn ($q) => $q->where('status', 'active'))
            ->when($request->filled('genre'), fn ($q) => $q->where('primary_genre_id', $request->genre))
            ->when($request->filled('artist'), fn ($q) => $q->where('artist_id', $request->artist))
            ->when($request->filled('album'), fn ($q) => $q->where('album_id', $request->album))
            ->when($request->filled('is_free'), fn ($q) => $q->where('is_free', $request->boolean('is_free')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%');
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return SongResource::collection($songs);
    }

    /**
     * GET /api/songs/{song}
     * Single song by ID, slug, or UUID.
     */
    public function show(string $song)
    {
        $record = Song::with(['artist', 'album', 'primaryGenre'])
            ->published()
            ->where(function ($q) use ($song) {
                $q->where('id', $song)
                  ->orWhere('slug', $song)
                  ->orWhere('uuid', $song);
            })
            ->firstOrFail();

        return new SongResource($record);
    }

    public function trending(Request $request): JsonResponse
    {
        try {
            $songs = $this->songService->getTrendingSongs(
                $request->get('days', 7),
                $request->get('limit', 20)
            );

            return response()->json([
                'success' => true,
                'data' => $songs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trending songs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function newReleases(Request $request): JsonResponse
    {
        try {
            $songs = $this->songService->getNewReleases(
                $request->get('days', 30),
                $request->get('limit', 20)
            );

            return response()->json([
                'success' => true,
                'data' => $songs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch new releases',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function byGenre(Request $request, string $genre): JsonResponse
    {
        try {
            $songs = $this->songService->getSongsByGenre(
                $genre,
                $request->get('per_page', 20)
            );

            return response()->json([
                'success' => true,
                'data' => $songs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch songs by genre',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->get('q');

            if (!$query) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search query is required'
                ], 400);
            }

            $songs = $this->songService->searchSongs(
                $query,
                $request->get('per_page', 20)
            );

            return response()->json([
                'success' => true,
                'data' => $songs
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function recordPlay(Request $request, Song $song): JsonResponse
    {
        try {
            $user = auth()->user();

            $playData = [
                'duration_played_seconds' => $request->get('play_duration_seconds', $request->get('duration_played_seconds', 0)),
                'completed' => $request->boolean('completed', false),
                'device_type' => $request->get('device_type', 'web'),
                'quality' => $request->get('quality', '128'),
            ];

            $playHistory = $this->songService->recordPlay($song, $user, $playData);

            return response()->json([
                'success' => true,
                'message' => 'Play recorded successfully',
                'data' => $playHistory
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], $e->getCode() === 403 ? 403 : 500);
        }
    }

    public function download(Request $request, Song $song): JsonResponse
    {
        try {
            $user = auth()->user();
            $result = $this->songService->downloadSong($song, $user);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'download_url' => $result['download_url'],
                'expires_at' => $result['expires_at']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ], 403);
        }
    }

    public function toggleLike(Song $song): JsonResponse
    {
        try {
            $user = auth()->user();
            $result = $this->songService->toggleLike($song, $user);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'is_liked' => $result['is_liked'],
                'like_count' => $result['like_count']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle like',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Alias for toggleLike method to match API routes
     */
    public function like(Song $song): JsonResponse
    {
        return $this->toggleLike($song);
    }

    /**
     * Check if current user has liked the song
     */
    public function isLiked(Song $song): JsonResponse
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => true,
                    'isLiked' => false
                ]);
            }

            // Check using polymorphic likes relationship
            $isLiked = $song->likes()
                ->where('user_id', auth()->id())
                ->exists();

            return response()->json([
                'success' => true,
                'isLiked' => $isLiked
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check like status',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Alias for recordPlay method to match API routes
     */
    public function play(Request $request, Song $song): JsonResponse
    {
        return $this->recordPlay($request, $song);
    }
}