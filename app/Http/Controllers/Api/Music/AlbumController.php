<?php

namespace App\Http\Controllers\Api\Music;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\SongResource;
use App\Models\Album;
use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AlbumController extends Controller
{
    /**
     * GET /api/albums
     * Paginated list of published albums.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $buildQuery = function () use ($request, $perPage) {
            return Album::with(['artist', 'primaryGenre'])
                ->published()
                ->whereHas('artist', fn ($q) => $q->whereIn('status', Artist::VISIBLE_STATUSES))
                ->when($request->filled('type'), fn ($q) => $q->where('album_type', $request->type))
                ->when($request->filled('genre'), fn ($q) => $q->where('primary_genre_id', $request->genre))
                ->when($request->filled('artist'), fn ($q) => $q->where('artist_id', $request->artist))
                ->when($request->filled('year'), fn ($q) => $q->whereYear('release_date', $request->year))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $q->where('title', 'like', '%'.escape_like($request->search).'%');
                })
                ->orderByDesc('release_date')
                ->paginate($perPage);
        };

        // Cache anonymous, non-search requests for 5 minutes
        $shouldCache = ! $request->user() && ! $request->filled('search');
        if ($shouldCache) {
            $cacheKey = 'api:albums:list:' . md5(serialize([
                'pp' => $perPage,
                't'  => $request->get('type'),
                'g'  => $request->get('genre'),
                'a'  => $request->get('artist'),
                'y'  => $request->get('year'),
                'pg' => $request->get('page', 1),
            ]));
            $albums = Cache::remember($cacheKey, 300, $buildQuery);
        } else {
            $albums = $buildQuery();
        }

        return AlbumResource::collection($albums);
    }

    /**
     * GET /api/albums/{album}
     * Single album by ID, slug, or UUID — eager-loads songs.
     */
    public function show(string $album)
    {
        $record = Album::with(['artist', 'primaryGenre', 'songs.artist'])
            ->published()
            ->where(function ($q) use ($album) {
                $q->where('id', $album)
                    ->orWhere('slug', $album)
                    ->orWhere('uuid', $album);
            })
            ->firstOrFail();

        return new AlbumResource($record);
    }

    /**
     * GET /api/albums/{album}/tracks
     * Paginated track-listing for an album, ordered by track number.
     */
    public function tracks(string $album, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 50), 100);

        $record = Album::published()
            ->where(function ($q) use ($album) {
                $q->where('id', $album)
                    ->orWhere('slug', $album)
                    ->orWhere('uuid', $album);
            })
            ->firstOrFail();

        $songs = $record->songs()
            ->with(['artist', 'album'])
            ->where('status', 'published')
            ->orderBy('track_number')
            ->paginate($perPage);

        return SongResource::collection($songs);
    }
}
