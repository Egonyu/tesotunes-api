<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\GenreResource;
use App\Http\Resources\SongResource;
use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenreController extends Controller
{
    /**
     * List all active genres.
     *
     * GET /api/genres
     */
    public function index(Request $request)
    {
        try {
            $genres = Genre::active()
                ->ordered()
                ->withCount(['songs' => function ($q) {
                    $q->where('status', 'published');
                }])
                ->get();

            return GenreResource::collection($genres);
        } catch (Throwable $exception) {
            Log::error('Failed to load public genres list', [
                'message' => $exception->getMessage(),
                'path' => $request->path(),
            ]);

            return GenreResource::collection(collect());
        }
    }

    /**
     * Show a single genre by slug.
     *
     * GET /api/genres/{slug}
     */
    public function showBySlug(string $slug)
    {
        $genre = Genre::active()
            ->where('slug', $slug)
            ->withCount(['songs' => function ($q) {
                $q->where('status', 'published');
            }])
            ->firstOrFail();

        return new GenreResource($genre);
    }

    /**
     * Show a single genre by ID.
     *
     * GET /api/genres/{id}
     */
    public function show(Genre $genre)
    {
        $genre->loadCount(['songs' => function ($q) {
            $q->where('status', 'published');
        }]);

        return new GenreResource($genre);
    }

    /**
     * Get songs in a genre (paginated).
     *
     * GET /api/genres/{id}/songs
     */
    public function songs(Request $request, Genre $genre)
    {
        $perPage = $this->getPerPage($request);

        $songs = $genre->songs()
            ->published()
            ->with(['artist', 'album', 'primaryGenre'])
            ->orderByDesc('play_count')
            ->paginate($perPage);

        return SongResource::collection($songs);
    }

    /**
     * Get artists in a genre (paginated).
     *
     * GET /api/genres/{id}/artists
     */
    public function artists(Request $request, Genre $genre)
    {
        $perPage = $this->getPerPage($request);

        $artists = \App\Models\Artist::where('primary_genre_id', $genre->id)
            ->with('primaryGenre')
            ->approved()
            ->orderByDesc('total_plays')
            ->paginate($perPage);

        return ArtistResource::collection($artists);
    }

    /**
     * Get albums in a genre (paginated).
     *
     * GET /api/genres/{id}/albums
     */
    public function albums(Request $request, Genre $genre)
    {
        $perPage = $this->getPerPage($request);

        $albums = \App\Models\Album::where('primary_genre_id', $genre->id)
            ->published()
            ->with(['artist', 'primaryGenre'])
            ->orderByDesc('release_date')
            ->paginate($perPage);

        return AlbumResource::collection($albums);
    }
}
