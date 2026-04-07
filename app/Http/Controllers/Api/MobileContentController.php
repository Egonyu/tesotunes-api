<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileContentController extends Controller
{
    /**
     * Get trending songs based on recent plays and likes
     */
    public function trendingSongs(Request $request)
    {
        $limit = $request->input('limit', 10);

        $songs = Song::with(['artist', 'album'])
            ->whereIn('status', ['approved', 'published'])
            ->where('visibility', 'public')
            ->select('songs.*')
            ->selectRaw('(play_count * 0.7 + like_count * 0.3) as trending_score')
            ->where(function ($query) {
                $query->where('created_at', '>=', now()->subDays(30))
                    ->orWhere('play_count', '>', 0);
            })
            ->orderByDesc('trending_score')
            ->limit($limit)
            ->get()
            ->map(function ($song) {
                return [
                    'id' => $song->id,
                    'title' => $song->title,
                    'artist' => $song->artist->name ?? 'Unknown Artist',
                    'artist_id' => $song->artist_id,
                    'artwork' => $song->artwork_url,
                    'duration_seconds' => $song->duration_seconds,
                    'duration_formatted' => $this->formatDuration($song->duration_seconds),
                    'play_count' => $song->play_count ?? 0,
                    'like_count' => $song->like_count ?? 0,
                    'is_explicit' => $song->is_explicit ?? false,
                    'audio_url' => $song->audio_file_320 ? \App\Helpers\StorageHelper::url($song->audio_file_320) : null,
                ];
            });

        return response()->json([
            'data' => $songs,
        ]);
    }

    /**
     * Get popular artists based on followers and plays
     */
    public function popularArtists(Request $request)
    {
        $limit = $request->input('limit', 10);

        $artists = Artist::withCount(['followers', 'songs'])
            ->with(['user'])
            ->where('is_verified', true)
            ->select('artists.*')
            ->selectRaw('(followers_count * 0.6 + songs_count * 0.4) as popularity_score')
            ->orderByDesc('popularity_score')
            ->limit($limit)
            ->get()
            ->map(function ($artist) {
                return [
                    'id' => $artist->id,
                    'name' => $artist->stage_name,
                    'bio' => $artist->bio ? (strlen($artist->bio) > 100 ? substr($artist->bio, 0, 100).'...' : $artist->bio) : null,
                    'avatar' => $artist->avatar_url,
                    'cover_image' => $artist->banner_url,
                    'is_verified' => $artist->is_verified,
                    'followers_count' => $artist->follower_count ?? 0,
                    'songs_count' => $artist->songs_count ?? 0,
                    'slug' => $artist->slug,
                ];
            });

        return response()->json([
            'data' => $artists,
        ]);
    }

    /**
     * Get popular albums
     */
    public function popularAlbums(Request $request)
    {
        $limit = $request->input('limit', 10);

        $albums = Album::with(['artist'])
            ->where('status', 'approved')
            ->select('albums.*')
            ->selectRaw('(play_count * 0.5 + download_count * 0.5) as popularity_score')
            ->orderByDesc('popularity_score')
            ->orderByDesc('release_date')
            ->limit($limit)
            ->get()
            ->map(function ($album) {
                return [
                    'id' => $album->id,
                    'title' => $album->title,
                    'artist' => $album->artist->name ?? 'Unknown Artist',
                    'artist_id' => $album->artist_id,
                    'artwork' => $album->artwork_url,
                    'type' => $album->type ?? 'album',
                    'release_date' => $album->release_date ? $album->release_date->format('Y') : null,
                    'total_tracks' => $album->total_tracks ?? 0,
                    'play_count' => $album->play_count ?? 0,
                    'is_explicit' => $album->is_explicit ?? false,
                    'slug' => $album->slug,
                ];
            });

        return response()->json([
            'data' => $albums,
        ]);
    }

    /**
     * Get radio stations (curated playlists)
     */
    public function radioStations(Request $request)
    {
        $limit = $request->input('limit', 10);

        $stations = Playlist::with(['user'])
            ->public()
            ->where(function ($query) {
                $query->where('is_featured', true)
                    ->orWhere('name', 'like', '%radio%')
                    ->orWhere('name', 'like', '%mix%')
                    ->orWhere('description', 'like', '%radio%')
                    ->orWhere('description', 'like', '%station%');
            })
            ->withCount('songs')
            ->orderByDesc('is_featured')
            ->orderByDesc('play_count')
            ->limit($limit)
            ->get()
            ->map(function ($playlist) {
                return [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'description' => $playlist->description ? (strlen($playlist->description) > 100 ? substr($playlist->description, 0, 100).'...' : $playlist->description) : null,
                    'artwork' => $playlist->artwork_url,
                    'curator' => $playlist->user->name ?? 'Music App',
                    'songs_count' => $playlist->songs_count ?? 0,
                    'play_count' => $playlist->play_count ?? 0,
                    'is_featured' => $playlist->is_featured ?? false,
                    'slug' => $playlist->slug,
                ];
            });

        return response()->json([
            'data' => $stations,
        ]);
    }

    /**
     * Get featured charts
     */
    public function featuredCharts(Request $request)
    {
        $limit = $request->input('limit', 10);

        // Get genre-based charts
        $charts = DB::table('genres')
            ->select('genres.id', 'genres.name', 'genres.slug')
            ->selectRaw('COUNT(songs.id) as songs_count')
            ->selectRaw('SUM(songs.play_count) as total_plays')
            ->leftJoin('songs', 'songs.primary_genre_id', '=', 'genres.id')
            ->where('songs.status', 'approved')
            ->groupBy('genres.id', 'genres.name', 'genres.slug')
            ->having('songs_count', '>', 0)
            ->orderByDesc('total_plays')
            ->limit($limit)
            ->get()
            ->map(function ($genre) {
                // Get top song for this genre as artwork
                $topSong = Song::where('primary_genre_id', $genre->id)
                    ->where('status', 'approved')
                    ->orderByDesc('play_count')
                    ->first();

                return [
                    'id' => $genre->id,
                    'title' => $genre->name.' Chart',
                    'genre' => $genre->name,
                    'description' => "Top {$genre->name} tracks this week",
                    'artwork' => $topSong && $topSong->artwork
                        ? \App\Helpers\StorageHelper::url($topSong->artwork)
                        : asset('images/charts/default-chart.png'),
                    'songs_count' => $genre->songs_count ?? 0,
                    'total_plays' => $genre->total_plays ?? 0,
                    'slug' => $genre->slug,
                ];
            });

        return response()->json([
            'data' => $charts,
        ]);
    }

    /**
     * Helper function to format duration
     */
    private function formatDuration($seconds)
    {
        if (! $seconds) {
            return '0:00';
        }

        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
