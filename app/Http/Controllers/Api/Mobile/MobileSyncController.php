<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\PlayHistory;
use App\Models\Like;
use App\Models\UserFollow;
use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MobileSyncController extends Controller
{
    /**
     * Get incremental sync data since last sync
     * Returns only changed data to minimize bandwidth
     */
    public function incrementalSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'last_sync_at' => 'nullable|date',
            'include' => 'nullable|array',
            'include.*' => 'string|in:songs,playlists,favorites,downloads,play_history,follows',
        ]);
        
        $user = $request->user();
        $lastSyncAt = $validated['last_sync_at'] ? Carbon::parse($validated['last_sync_at']) : now()->subDays(30);
        $include = $validated['include'] ?? ['songs', 'playlists', 'favorites', 'downloads', 'play_history'];
        
        $syncData = [
            'sync_timestamp' => now()->toISOString(),
            'last_sync_at' => $lastSyncAt->toISOString(),
        ];
        
        // User's downloaded songs (for offline access)
        if (in_array('downloads', $include)) {
            $syncData['downloaded_songs'] = $this->getSyncedDownloads($user, $lastSyncAt);
        }
        
        // User's playlists
        if (in_array('playlists', $include)) {
            $syncData['playlists'] = $this->getSyncedPlaylists($user, $lastSyncAt);
        }
        
        // Liked songs
        if (in_array('favorites', $include)) {
            $syncData['liked_songs'] = $this->getSyncedLikes($user, $lastSyncAt);
        }
        
        // Play history for analytics
        if (in_array('play_history', $include)) {
            $syncData['play_history'] = $this->getSyncedPlayHistory($user, $lastSyncAt);
        }
        
        // Following artists
        if (in_array('follows', $include)) {
            $syncData['following'] = $this->getSyncedFollows($user, $lastSyncAt);
        }
        
        // New/updated songs from followed artists
        if (in_array('songs', $include)) {
            $syncData['new_songs_from_artists'] = $this->getNewSongsFromFollowedArtists($user, $lastSyncAt);
        }
        
        return response()->json([
            'success' => true,
            'sync_data' => $syncData,
        ]);
    }
    
    /**
     * Full sync - returns complete user library
     * Used for first-time sync or after app reinstall
     */
    public function fullSync(Request $request): JsonResponse
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'sync_data' => [
                'sync_timestamp' => now()->toISOString(),
                'sync_type' => 'full',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'subscription_tier' => $user->subscription_tier,
                    'subscription_expires_at' => $user->subscription_expires_at?->toISOString(),
                ],
                'downloaded_songs' => $this->getAllDownloads($user),
                'playlists' => $this->getAllPlaylists($user),
                'liked_songs' => $this->getAllLikes($user),
                'play_history' => $this->getRecentPlayHistory($user, 100),
                'following' => $this->getAllFollows($user),
                'statistics' => [
                    'total_downloads' => Download::where('user_id', $user->id)->count(),
                    'total_playlists' => Playlist::where('user_id', $user->id)->count(),
                    'total_liked_songs' => Like::where('user_id', $user->id)->where('likeable_type', Song::class)->count(),
                    'total_play_count' => PlayHistory::where('user_id', $user->id)->count(),
                ],
            ],
        ]);
    }
    
    /**
     * Batch sync play history from mobile (for offline plays)
     */
    public function syncPlayHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plays' => 'required|array|max:100',
            'plays.*.song_id' => 'required|integer|exists:songs,id',
            'plays.*.played_at' => 'required|date',
            'plays.*.duration_played' => 'nullable|integer|min:0',
            'plays.*.completed' => 'nullable|boolean',
        ]);
        
        $user = $request->user();
        $synced = 0;
        $errors = [];
        
        DB::beginTransaction();
        try {
            foreach ($validated['plays'] as $play) {
                try {
                    // Check if play already exists (prevent duplicates)
                    $exists = PlayHistory::where('user_id', $user->id)
                        ->where('song_id', $play['song_id'])
                        ->where('played_at', Carbon::parse($play['played_at']))
                        ->exists();
                    
                    if (!$exists) {
                        PlayHistory::create([
                            'user_id' => $user->id,
                            'song_id' => $play['song_id'],
                            'played_at' => Carbon::parse($play['played_at']),
                            'duration_played' => $play['duration_played'] ?? null,
                            'completed' => $play['completed'] ?? false,
                        ]);
                        
                        // Increment song play count
                        Song::where('id', $play['song_id'])->increment('play_count');
                        
                        $synced++;
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'song_id' => $play['song_id'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'synced' => $synced,
                'total' => count($validated['plays']),
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to sync play history',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Sync user actions (likes, follows) from offline mode
     */
    public function syncUserActions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'likes' => 'nullable|array',
            'likes.*.song_id' => 'required|integer|exists:songs,id',
            'likes.*.action' => 'required|in:like,unlike',
            'likes.*.timestamp' => 'required|date',
            
            'follows' => 'nullable|array',
            'follows.*.artist_id' => 'required|integer|exists:artists,id',
            'follows.*.action' => 'required|in:follow,unfollow',
            'follows.*.timestamp' => 'required|date',
        ]);
        
        $user = $request->user();
        $results = [
            'likes_synced' => 0,
            'follows_synced' => 0,
            'errors' => [],
        ];
        
        DB::beginTransaction();
        try {
            // Sync likes
            if (isset($validated['likes'])) {
                foreach ($validated['likes'] as $like) {
                    try {
                        if ($like['action'] === 'like') {
                            Like::firstOrCreate([
                                'user_id' => $user->id,
                                'likeable_type' => Song::class,
                                'likeable_id' => $like['song_id'],
                            ]);
                            
                            Song::where('id', $like['song_id'])->increment('like_count');
                        } else {
                            Like::where('user_id', $user->id)
                                ->where('likeable_type', Song::class)
                                ->where('likeable_id', $like['song_id'])
                                ->delete();
                            
                            Song::where('id', $like['song_id'])->decrement('like_count');
                        }
                        
                        $results['likes_synced']++;
                    } catch (\Exception $e) {
                        $results['errors'][] = [
                            'type' => 'like',
                            'song_id' => $like['song_id'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
            
            // Sync follows
            if (isset($validated['follows'])) {
                foreach ($validated['follows'] as $follow) {
                    try {
                        $artist = Artist::find($follow['artist_id']);
                        if (!$artist || !$artist->user_id) {
                            $results['errors'][] = [
                                'type' => 'follow',
                                'artist_id' => $follow['artist_id'],
                                'error' => 'Artist not found or has no associated user',
                            ];
                            continue;
                        }
                        
                        if ($follow['action'] === 'follow') {
                            UserFollow::firstOrCreate([
                                'follower_id' => $user->id,
                                'following_type' => 'App\\Models\\Artist',
                                'following_id' => $artist->user_id,
                            ]);
                        } else {
                            UserFollow::where('follower_id', $user->id)
                                ->where('following_type', 'App\\Models\\Artist')
                                ->where('following_id', $artist->user_id)
                                ->delete();
                        }
                        
                        $results['follows_synced']++;
                    } catch (\Exception $e) {
                        $results['errors'][] = [
                            'type' => 'follow',
                            'artist_id' => $follow['artist_id'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to sync user actions',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    // Private helper methods
    
    private function getSyncedDownloads($user, $since)
    {
        return Download::where('user_id', $user->id)
            ->where('created_at', '>', $since)
            ->with('song:id,title,artist_id,artwork,duration_seconds')
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'song_id' => $d->song_id,
                'song' => $d->song,
                'downloaded_at' => $d->created_at->toISOString(),
            ]);
    }
    
    private function getAllDownloads($user)
    {
        return Download::where('user_id', $user->id)
            ->with('song:id,title,artist_id,artwork,duration_seconds')
            ->latest()
            ->take(500)
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'song_id' => $d->song_id,
                'song' => $d->song,
                'downloaded_at' => $d->created_at->toISOString(),
            ]);
    }
    
    private function getSyncedPlaylists($user, $since)
    {
        return Playlist::where('user_id', $user->id)
            ->where('updated_at', '>', $since)
            ->with('songs:id,title,artist_id,artwork,duration_seconds')
            ->get();
    }
    
    private function getAllPlaylists($user)
    {
        return Playlist::where('user_id', $user->id)
            ->with('songs:id,title,artist_id,artwork,duration_seconds')
            ->get();
    }
    
    private function getSyncedLikes($user, $since)
    {
        return Like::where('user_id', $user->id)
            ->where('likeable_type', Song::class)
            ->where('created_at', '>', $since)
            ->pluck('likeable_id');
    }
    
    private function getAllLikes($user)
    {
        return Like::where('user_id', $user->id)
            ->where('likeable_type', Song::class)
            ->pluck('likeable_id');
    }
    
    private function getSyncedPlayHistory($user, $since)
    {
        return PlayHistory::where('user_id', $user->id)
            ->where('played_at', '>', $since)
            ->latest('played_at')
            ->take(100)
            ->get();
    }
    
    private function getRecentPlayHistory($user, $limit)
    {
        return PlayHistory::where('user_id', $user->id)
            ->latest('played_at')
            ->take($limit)
            ->get();
    }
    
    private function getSyncedFollows($user, $since)
    {
        return UserFollow::where('follower_id', $user->id)
            ->where('following_type', 'App\\Models\\Artist')
            ->where('created_at', '>', $since)
            ->pluck('following_id');
    }
    
    private function getAllFollows($user)
    {
        return UserFollow::where('follower_id', $user->id)
            ->where('following_type', 'App\\Models\\Artist')
            ->pluck('following_id');
    }
    
    private function getNewSongsFromFollowedArtists($user, $since)
    {
        $followedUserIds = UserFollow::where('follower_id', $user->id)
            ->where('following_type', 'App\\Models\\Artist')
            ->pluck('following_id');
        
        if ($followedUserIds->isEmpty()) {
            return [];
        }
        
        // Get artist IDs from user IDs
        $followedArtistIds = Artist::whereIn('user_id', $followedUserIds)
            ->pluck('id');
        
        if ($followedArtistIds->isEmpty()) {
            return [];
        }
        
        return Song::whereIn('artist_id', $followedArtistIds)
            ->where('created_at', '>', $since)
            ->where('status', 'approved')
            ->with('artist:id,stage_name')
            ->latest()
            ->take(50)
            ->get();
    }
}
