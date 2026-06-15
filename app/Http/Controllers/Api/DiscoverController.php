<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Song;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $type = $request->get('type', 'all');
        $genre = $request->get('genre');
        $sortBy = $request->get('sort', 'relevance');
        $limit = $request->get('limit', 20);

        $results = [
            'songs' => [],
            'artists' => [],
            'albums' => [],
            'playlists' => [],
        ];

        if (strlen($query) >= 2) {
            // Search songs
            if ($type === 'all' || $type === 'songs') {
                $songQuery = Song::published()
                    ->withOptimizedRelations()
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'LIKE', "%{$query}%")
                            ->orWhere('lyrics', 'LIKE', "%{$query}%")
                            ->orWhereHas('artist', function ($subQuery) use ($query) {
                                $subQuery->where('stage_name', 'LIKE', "%{$query}%");
                            });
                    });

                if ($genre) {
                    $songQuery->byGenre($genre);
                }

                $this->applySorting($songQuery, $sortBy, $query, 'title');
                $results['songs'] = $songQuery->take($limit)->get();
            }

            // Search artists
            if ($type === 'all' || $type === 'artists') {
                $artistQuery = Artist::approved()
                    ->withFreshStats()
                    ->where(function ($q) use ($query) {
                        $q->where('stage_name', 'LIKE', "%{$query}%")
                            ->orWhere('bio', 'LIKE', "%{$query}%");
                    });

                $this->applySorting($artistQuery, $sortBy, $query, 'stage_name');
                $results['artists'] = $artistQuery->take($limit)->get();
            }

            // Search albums
            if ($type === 'all' || $type === 'albums') {
                $albumQuery = Album::published()
                    ->with(['artist'])
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'LIKE', "%{$query}%")
                            ->orWhereHas('artist', function ($subQuery) use ($query) {
                                $subQuery->where('stage_name', 'LIKE', "%{$query}%");
                            });
                    });

                if ($genre) {
                    $albumQuery->where('primary_genre_id', $genre);
                }

                $this->applySorting($albumQuery, $sortBy, $query, 'title');
                $results['albums'] = $albumQuery->take($limit)->get();
            }

            // Search playlists (the playlists table uses `name`, not `title`).
            if ($type === 'all' || $type === 'playlists') {
                $playlistQuery = Playlist::public()
                    ->with(['owner'])
                    ->withCount(['songs', 'followers'])
                    ->where(function ($q) use ($query) {
                        $q->where('name', 'LIKE', "%{$query}%")
                            ->orWhere('description', 'LIKE', "%{$query}%");
                    });

                $this->applySorting($playlistQuery, $sortBy, $query, 'name');
                $results['playlists'] = $playlistQuery->take($limit)->get();
            }
        }

        return response()->json([
            'data' => [
                'query' => $query,
                'type' => $type,
                'results' => $results,
                'total_results' => collect($results)->sum(fn ($items) => count($items)),
            ],
        ]);
    }

    public function trending(Request $request)
    {
        $period = $request->get('period', '7d');
        $limit = $request->get('limit', 20);

        $days = match ($period) {
            '1d' => 1,
            '7d' => 7,
            '30d' => 30,
            default => 7
        };

        $trendingSongs = Song::published()
            ->withOptimizedRelations()
            ->trending($days)
            ->take($limit)
            ->get();

        $trendingArtists = Artist::approved()
            ->withFreshStats()
            ->whereHas('songs', function ($query) use ($days) {
                $query->published()
                    ->where('created_at', '>=', now()->subDays($days));
            })
            ->orderBy('total_plays', 'desc')
            ->take(10)
            ->get();

        return response()->json([
            'data' => [
                'period' => $period,
                'trending_songs' => $trendingSongs,
                'trending_artists' => $trendingArtists,
            ],
        ]);
    }

    public function genres(Request $request)
    {
        $withSongs = $request->boolean('with_songs', false);
        $limit = $request->get('limit', 50);

        $query = Genre::withCount(['songs' => function ($query) {
            $query->published();
        }])
            ->having('songs_count', '>', 0)
            ->orderBy('songs_count', 'desc');

        if ($withSongs) {
            $query->with(['songs' => function ($query) {
                $query->published()
                    ->withOptimizedRelations()
                    ->orderBy('play_count', 'desc')
                    ->limit(5);
            }]);
        }

        $genres = $query->take($limit)->get();

        return response()->json([
            'data' => $genres,
        ]);
    }

    public function artists(Request $request)
    {
        $sortBy = $request->get('sort', 'popular');
        $limit = $request->get('limit', 20);

        $query = Artist::approved()->withFreshStats();

        switch ($sortBy) {
            case 'recent':
                $query->orderBy('created_at', 'desc');
                break;
            case 'followers':
                $query->orderBy('followers_count', 'desc');
                break;
            case 'name':
                $query->orderBy('stage_name', 'asc');
                break;
            case 'popular':
            default:
                $query->orderBy('total_plays', 'desc');
                break;
        }

        $artists = $query->take($limit)->get();

        return response()->json([
            'data' => $artists,
        ]);
    }

    public function artistDetails(Artist $artist)
    {
        $artist->loadCount(['songs as songs_count' => function ($query) {
            $query->where('status', 'published');
        }]);

        return response()->json([
            'data' => $artist,
        ]);
    }

    public function artistSongs(Artist $artist, Request $request)
    {
        $sortBy = $request->get('sort', 'play_count');
        $limit = $request->get('limit', 25);

        $sortOrder = 'desc';
        switch ($sortBy) {
            case 'title':
                $sortOrder = 'asc';
                break;
            case 'created_at':
            case 'release_date':
                $sortOrder = 'desc';
                break;
            case 'play_count':
            default:
                $sortBy = 'play_count';
                $sortOrder = 'desc';
                break;
        }

        $songs = $artist->songs()
            ->where('status', 'published')
            ->withOptimizedRelations()
            ->orderBy($sortBy, $sortOrder)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => [
                'artist' => [
                    'id' => $artist->id,
                    'stage_name' => $artist->stage_name,
                    'avatar' => $artist->avatar,
                ],
                'songs' => $songs,
            ],
        ]);
    }

    private function applySorting($query, $sortBy, $searchQuery, $titleField)
    {
        switch ($sortBy) {
            case 'plays':
                if (method_exists($query->getModel(), 'play_count')) {
                    $query->orderBy('play_count', 'desc');
                } elseif (method_exists($query->getModel(), 'total_plays')) {
                    $query->orderBy('total_plays', 'desc');
                } else {
                    $query->orderBy('created_at', 'desc');
                }
                break;
            case 'recent':
                $query->orderBy('created_at', 'desc');
                break;
            case 'title':
                $query->orderBy($titleField, 'asc');
                break;
            default: // relevance
                // ✅ SECURITY FIX: Use parameterized queries to prevent SQL injection
                $query->orderByRaw("
                    CASE
                        WHEN {$titleField} LIKE ? THEN 1
                        WHEN {$titleField} LIKE ? THEN 2
                        ELSE 3
                    END
                ", [$searchQuery.'%', '%'.$searchQuery.'%']);

                if (method_exists($query->getModel(), 'play_count')) {
                    $query->orderBy('play_count', 'desc');
                } elseif (method_exists($query->getModel(), 'total_plays')) {
                    $query->orderBy('total_plays', 'desc');
                } else {
                    $query->orderBy('created_at', 'desc');
                }
        }
    }
}
