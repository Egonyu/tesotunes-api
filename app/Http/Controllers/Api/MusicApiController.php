<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MusicApiController extends Controller
{
    /**
     * Get all songs
     */
    public function songs(Request $request)
    {
        $perPage = $request->get('limit', 20);
        $genreId = $request->get('genre');
        
        $query = DB::table('songs')
            ->select([
                'songs.id',
                'songs.uuid',
                'songs.title',
                'songs.slug',
                'songs.artwork',
                'songs.audio_file_320 as audio_url',
                'songs.duration_seconds as duration',
                'songs.play_count',
                'songs.like_count',
                'songs.is_explicit',
                'songs.release_date',
                'songs.created_at',
                'artists.id as artist_id',
                'artists.stage_name as artist_name',
                'artists.slug as artist_slug',
                'artists.avatar as artist_avatar',
                'albums.id as album_id',
                'albums.title as album_title',
                'albums.slug as album_slug',
                'albums.artwork as album_artwork',
                'genres.id as genre_id',
                'genres.name as genre_name',
                'genres.slug as genre_slug'
            ])
            ->join('artists', 'songs.artist_id', '=', 'artists.id')
            ->leftJoin('albums', 'songs.album_id', '=', 'albums.id')
            ->leftJoin('genres', 'songs.primary_genre_id', '=', 'genres.id')
            ->where('songs.status', 'published')
            ->where('artists.status', 'active')
            ->orderBy('songs.created_at', 'desc');
        
        if ($genreId) {
            $query->where('songs.primary_genre_id', $genreId);
        }
        
        $songs = $query->paginate($perPage);
        
        // Transform data to include full URLs
        $data = collect($songs->items())->map(function ($song) {
            $song->artwork_url = $song->artwork 
                ? url('storage/' . $song->artwork) 
                : null;
            $song->audio_url = $song->audio_url 
                ? url('storage/' . $song->audio_url) 
                : null;
            return $song;
        })->toArray();
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $songs->currentPage(),
                'total' => $songs->total(),
                'per_page' => $songs->perPage(),
                'last_page' => $songs->lastPage(),
            ],
        ]);
    }

    /**
     * Get single song
     */
    public function song($id)
    {
        $song = DB::table('songs')
            ->select([
                'songs.*',
                'artists.stage_name as artist_name',
                'artists.slug as artist_slug',
                'artists.avatar as artist_avatar',
                'albums.title as album_title',
                'albums.slug as album_slug',
                'albums.artwork as album_artwork',
                'genres.name as genre_name',
                'genres.slug as genre_slug'
            ])
            ->join('artists', 'songs.artist_id', '=', 'artists.id')
            ->leftJoin('albums', 'songs.album_id', '=', 'albums.id')
            ->leftJoin('genres', 'songs.primary_genre_id', '=', 'genres.id')
            ->where('songs.id', $id)
            ->orWhere('songs.slug', $id)
            ->first();
        
        if (!$song) {
            return response()->json([
                'success' => false,
                'message' => 'Song not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $song,
        ]);
    }

    /**
     * Get all artists
     */
    public function artists(Request $request)
    {
        $perPage = $request->get('limit', 20);
        
        $artists = DB::table('artists')
            ->select([
                'id',
                'uuid',
                'stage_name as name',
                'slug',
                'bio',
                'avatar',
                'banner',
                'country',
                'city',
                'is_verified',
                'verification_badge',
                'total_plays',
                'total_songs',
                'total_albums',
                'follower_count',
                'created_at'
            ])
            ->where('status', 'active')
            ->orderBy('follower_count', 'desc')
            ->paginate($perPage);
        
        // Transform data to include full URLs
        $data = collect($artists->items())->map(function ($artist) {
            $artist->avatar_url = $artist->avatar 
                ? url('storage/' . $artist->avatar) 
                : null;
            $artist->banner_url = $artist->banner 
                ? url('storage/' . $artist->banner) 
                : null;
            return $artist;
        })->toArray();
        
        return response()->json([
            'success' => true,
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
     * Get single artist
     */
    public function artist($id)
    {
        $artist = DB::table('artists')
            ->select([
                'id',
                'uuid',
                'stage_name as name',
                'slug',
                'bio',
                'avatar',
                'banner',
                'country',
                'city',
                'social_links',
                'is_verified',
                'verification_badge',
                'total_plays',
                'total_songs',
                'total_albums',
                'follower_count',
                'created_at'
            ])
            ->where(function($query) use ($id) {
                $query->where('id', $id)->orWhere('slug', $id);
            })
            ->where('status', 'active')
            ->first();
        
        if (!$artist) {
            return response()->json([
                'success' => false,
                'message' => 'Artist not found',
            ], 404);
        }
        
        // Add full URLs
        $artist->avatar_url = $artist->avatar 
            ? url('storage/' . $artist->avatar) 
            : null;
        $artist->banner_url = $artist->banner 
            ? url('storage/' . $artist->banner) 
            : null;
        
        return response()->json([
            'success' => true,
            'data' => $artist,
        ]);
    }

    /**
     * Get artist songs
     */
    public function artistSongs($id, Request $request)
    {
        $perPage = $request->get('limit', 20);
        
        $songs = DB::table('songs')
            ->select([
                'songs.id',
                'songs.uuid',
                'songs.title',
                'songs.slug',
                'songs.artwork',
                'songs.audio_file_320 as audio_url',
                'songs.duration_seconds as duration',
                'songs.play_count',
                'songs.like_count',
                'songs.is_explicit',
                'albums.id as album_id',
                'albums.title as album_title',
                'albums.slug as album_slug'
            ])
            ->join('artists', 'songs.artist_id', '=', 'artists.id')
            ->leftJoin('albums', 'songs.album_id', '=', 'albums.id')
            ->where('songs.status', 'published')
            ->where(function($query) use ($id) {
                $query->where('artists.id', $id)->orWhere('artists.slug', $id);
            })
            ->orderBy('songs.play_count', 'desc')
            ->paginate($perPage);
        
        // Transform data to include full URLs
        $data = collect($songs->items())->map(function ($song) {
            $song->artwork_url = $song->artwork 
                ? url('storage/' . $song->artwork) 
                : null;
            $song->audio_url = $song->audio_url 
                ? url('storage/' . $song->audio_url) 
                : null;
            return $song;
        })->toArray();
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $songs->currentPage(),
                'total' => $songs->total(),
                'per_page' => $songs->perPage(),
                'last_page' => $songs->lastPage(),
            ],
        ]);
    }

    /**
     * Get artist albums
     */
    public function artistAlbums($id, Request $request)
    {
        $perPage = $request->get('limit', 20);
        
        $albums = DB::table('albums')
            ->select([
                'albums.id',
                'albums.uuid',
                'albums.title',
                'albums.slug',
                'albums.description',
                'albums.artwork',
                'albums.album_type',
                'albums.release_date',
                'albums.total_tracks',
                'albums.play_count'
            ])
            ->join('artists', 'albums.artist_id', '=', 'artists.id')
            ->where('albums.status', 'published')
            ->where(function($query) use ($id) {
                $query->where('artists.id', $id)->orWhere('artists.slug', $id);
            })
            ->orderBy('albums.release_date', 'desc')
            ->paginate($perPage);
        
        // Transform data to include full URLs
        $data = collect($albums->items())->map(function ($album) {
            $album->artwork_url = $album->artwork 
                ? url('storage/' . $album->artwork) 
                : null;
            return $album;
        })->toArray();
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $albums->currentPage(),
                'total' => $albums->total(),
                'per_page' => $albums->perPage(),
                'last_page' => $albums->lastPage(),
            ],
        ]);
    }

    /**
     * Get all albums
     */
    public function albums(Request $request)
    {
        $perPage = $request->get('limit', 20);
        
        $albums = DB::table('albums')
            ->select([
                'albums.id',
                'albums.uuid',
                'albums.title',
                'albums.slug',
                'albums.description',
                'albums.artwork',
                'albums.album_type',
                'albums.release_date',
                'albums.release_year',
                'albums.total_tracks',
                'albums.total_duration_seconds',
                'albums.play_count',
                'albums.like_count',
                'albums.is_explicit',
                'albums.created_at',
                'artists.id as artist_id',
                'artists.stage_name as artist_name',
                'artists.slug as artist_slug',
                'artists.avatar as artist_avatar'
            ])
            ->join('artists', 'albums.artist_id', '=', 'artists.id')
            ->where('albums.status', 'published')
            ->where('artists.status', 'active')
            ->orderBy('albums.release_date', 'desc')
            ->paginate($perPage);
        
        // Transform data to include full URLs
        $data = collect($albums->items())->map(function ($album) {
            $album->artwork_url = $album->artwork 
                ? url('storage/' . $album->artwork) 
                : null;
            return $album;
        })->toArray();
        
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $albums->currentPage(),
                'total' => $albums->total(),
                'per_page' => $albums->perPage(),
                'last_page' => $albums->lastPage(),
            ],
        ]);
    }

    /**
     * Get single album
     */
    public function album($id)
    {
        $album = DB::table('albums')
            ->select([
                'albums.*',
                'artists.stage_name as artist_name',
                'artists.slug as artist_slug',
                'artists.avatar as artist_avatar'
            ])
            ->join('artists', 'albums.artist_id', '=', 'artists.id')
            ->where(function($query) use ($id) {
                $query->where('albums.id', $id)->orWhere('albums.slug', $id);
            })
            ->where('albums.status', 'published')
            ->first();
        
        if (!$album) {
            return response()->json([
                'success' => false,
                'message' => 'Album not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $album,
        ]);
    }

    /**
     * Get album songs
     */
    public function albumSongs($id, Request $request)
    {
        $perPage = $request->get('limit', 50);
        
        $songs = DB::table('songs')
            ->select([
                'songs.id',
                'songs.uuid',
                'songs.title',
                'songs.slug',
                'songs.artwork',
                'songs.audio_file_320 as audio_url',
                'songs.duration_seconds as duration',
                'songs.track_number',
                'songs.disc_number',
                'songs.play_count',
                'songs.like_count',
                'songs.is_explicit'
            ])
            ->join('albums', 'songs.album_id', '=', 'albums.id')
            ->where('songs.status', 'published')
            ->where(function($query) use ($id) {
                $query->where('albums.id', $id)->orWhere('albums.slug', $id);
            })
            ->orderBy('songs.disc_number', 'asc')
            ->orderBy('songs.track_number', 'asc')
            ->paginate($perPage);
        
        return response()->json([
            'success' => true,
            'data' => $songs->items(),
            'pagination' => [
                'current_page' => $songs->currentPage(),
                'total' => $songs->total(),
                'per_page' => $songs->perPage(),
                'last_page' => $songs->lastPage(),
            ],
        ]);
    }

    /**
     * Get trending songs
     */
    public function trending(Request $request)
    {
        $limit = $request->get('limit', 10);
        
        $songs = DB::table('songs')
            ->select([
                'songs.id',
                'songs.uuid',
                'songs.title',
                'songs.slug',
                'songs.artwork',
                'songs.audio_file_320 as audio_url',
                'songs.duration_seconds as duration',
                'songs.play_count',
                'songs.like_count',
                'songs.is_explicit',
                'artists.id as artist_id',
                'artists.stage_name as artist_name',
                'artists.slug as artist_slug',
                'artists.avatar as artist_avatar'
            ])
            ->join('artists', 'songs.artist_id', '=', 'artists.id')
            ->where('songs.status', 'published')
            ->where('artists.status', 'active')
            ->orderBy('songs.play_count', 'desc')
            ->limit($limit)
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $songs,
        ]);
    }

    /**
     * Get playlists
     */
    public function playlists(Request $request)
    {
        $perPage = $request->get('limit', 20);
        
        $playlists = DB::table('playlists')
            ->select([
                'playlists.id',
                'playlists.uuid',
                'playlists.name',
                'playlists.slug',
                'playlists.description',
                'playlists.artwork',
                'playlists.is_public',
                'playlists.total_songs',
                'playlists.total_duration_seconds',
                'playlists.follower_count',
                'playlists.created_at',
                'users.name as creator_name'
            ])
            ->join('users', 'playlists.user_id', '=', 'users.id')
            ->where('playlists.is_public', true)
            ->orderBy('playlists.follower_count', 'desc')
            ->paginate($perPage);
        
        return response()->json([
            'success' => true,
            'data' => $playlists->items(),
            'pagination' => [
                'current_page' => $playlists->currentPage(),
                'total' => $playlists->total(),
                'per_page' => $playlists->perPage(),
                'last_page' => $playlists->lastPage(),
            ],
        ]);
    }

    /**
     * Get single playlist
     */
    public function playlist($id)
    {
        $playlist = DB::table('playlists')
            ->select([
                'playlists.*',
                'users.name as creator_name'
            ])
            ->join('users', 'playlists.user_id', '=', 'users.id')
            ->where(function($query) use ($id) {
                $query->where('playlists.id', $id)->orWhere('playlists.slug', $id);
            })
            ->where('playlists.is_public', true)
            ->first();
        
        if (!$playlist) {
            return response()->json([
                'success' => false,
                'message' => 'Playlist not found',
            ], 404);
        }
        
        // Get playlist songs
        $songs = DB::table('playlist_songs')
            ->select([
                'songs.id',
                'songs.uuid',
                'songs.title',
                'songs.slug',
                'songs.artwork',
                'songs.audio_file_320 as audio_url',
                'songs.duration_seconds as duration',
                'artists.stage_name as artist_name',
                'artists.slug as artist_slug',
                'playlist_songs.position'
            ])
            ->join('songs', 'playlist_songs.song_id', '=', 'songs.id')
            ->join('artists', 'songs.artist_id', '=', 'artists.id')
            ->where('playlist_songs.playlist_id', $playlist->id)
            ->where('songs.status', 'published')
            ->orderBy('playlist_songs.position', 'asc')
            ->get();
        
        $playlist->songs = $songs;
        
        return response()->json([
            'success' => true,
            'data' => $playlist,
        ]);
    }

    /**
     * Get featured playlists
     */
    public function featuredPlaylists(Request $request)
    {
        $limit = $request->get('limit', 10);

        $playlists = DB::table('playlists')
            ->select([
                'playlists.id',
                'playlists.uuid',
                'playlists.name',
                'playlists.slug',
                'playlists.description',
                'playlists.artwork',
                'playlists.is_public',
                'playlists.song_count',
                'playlists.total_duration_seconds',
                'playlists.follower_count',
                'playlists.play_count',
                'playlists.created_at',
                'users.name as creator_name'
            ])
            ->join('users', 'playlists.user_id', '=', 'users.id')
            ->where('playlists.is_featured', true)
            ->where('playlists.is_public', true)
            ->whereNull('playlists.deleted_at')
            ->orderBy('playlists.follower_count', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $playlists,
        ]);
    }
}
