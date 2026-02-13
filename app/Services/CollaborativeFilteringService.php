<?php

namespace App\Services;

use App\Models\User;
use App\Models\Song;
use App\Models\PlayHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Collaborative Filtering Service
 * 
 * Implements collaborative filtering for personalized recommendations
 * Uses user-based and item-based similarity calculations
 */
class CollaborativeFilteringService
{
    private const SIMILARITY_THRESHOLD = 0.3;
    private const MAX_SIMILAR_USERS = 50;
    private const MAX_RECOMMENDATIONS = 30;
    
    /**
     * Get personalized song recommendations using collaborative filtering
     */
    public function getRecommendations(User $user, int $limit = 20): Collection
    {
        return Cache::tags(["user:{$user->id}", 'recommendations'])
            ->remember("user:{$user->id}:cf_recommendations", 1800, function () use ($user, $limit) {
                return $this->calculateRecommendations($user, $limit);
            });
    }
    
    /**
     * Calculate recommendations using collaborative filtering
     */
    private function calculateRecommendations(User $user, int $limit): Collection
    {
        try {
            // Get user's listening history
            $userSongs = $this->getUserSongInteractions($user);
            
            if ($userSongs->isEmpty()) {
                // New user - return popular songs
                return $this->getFallbackRecommendations($limit);
            }
            
            // Find similar users
            $similarUsers = $this->findSimilarUsers($user, $userSongs);
            
            if ($similarUsers->isEmpty()) {
                return $this->getFallbackRecommendations($limit);
            }
            
            // Get songs from similar users that current user hasn't heard
            $recommendations = $this->generateRecommendationsFromSimilarUsers(
                $user,
                $similarUsers,
                $userSongs,
                $limit
            );
            
            return $recommendations;
            
        } catch (\Exception $e) {
            Log::error('Collaborative filtering failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return $this->getFallbackRecommendations($limit);
        }
    }
    
    /**
     * Get user's song interactions with weighted scores
     */
    private function getUserSongInteractions(User $user): Collection
    {
        return Cache::tags(["user:{$user->id}"])->remember(
            "user:{$user->id}:song_interactions",
            3600,
            function () use ($user) {
                $interactions = collect();
                
                // Play history (weight: 1.0)
                $plays = PlayHistory::where('user_id', $user->id)
                    ->select('song_id', DB::raw('COUNT(*) as play_count'))
                    ->groupBy('song_id')
                    ->get()
                    ->mapWithKeys(fn($p) => [$p->song_id => $p->play_count * 1.0]);
                
                // Likes (weight: 3.0)
                $likes = $user->likes()
                    ->where('likeable_type', Song::class)
                    ->pluck('likeable_id')
                    ->mapWithKeys(fn($id) => [$id => 3.0]);
                
                // Downloads (weight: 2.0)
                $downloads = $user->downloads()
                    ->select('song_id')
                    ->distinct()
                    ->pluck('song_id')
                    ->mapWithKeys(fn($id) => [$id => 2.0]);
                
                // Playlist additions (weight: 2.5)
                $playlistSongs = DB::table('playlist_song')
                    ->join('playlists', 'playlists.id', '=', 'playlist_song.playlist_id')
                    ->where('playlists.user_id', $user->id)
                    ->select('playlist_song.song_id')
                    ->distinct()
                    ->pluck('song_id')
                    ->mapWithKeys(fn($id) => [$id => 2.5]);
                
                // Merge all interactions
                $allSongIds = collect([
                    $plays->keys(),
                    $likes->keys(),
                    $downloads->keys(),
                    $playlistSongs->keys()
                ])->flatten()->unique();
                
                foreach ($allSongIds as $songId) {
                    $score = 0;
                    $score += $plays->get($songId, 0);
                    $score += $likes->get($songId, 0);
                    $score += $downloads->get($songId, 0);
                    $score += $playlistSongs->get($songId, 0);
                    
                    $interactions[$songId] = $score;
                }
                
                return $interactions;
            }
        );
    }
    
    /**
     * Find similar users based on listening patterns
     */
    private function findSimilarUsers(User $user, Collection $userSongs): Collection
    {
        // Get users who have listened to similar songs
        $candidateUsers = PlayHistory::whereIn('song_id', $userSongs->keys())
            ->where('user_id', '!=', $user->id)
            ->select('user_id')
            ->distinct()
            ->limit(200)
            ->pluck('user_id');
        
        $similarities = collect();
        
        foreach ($candidateUsers as $candidateId) {
            $candidateSongs = $this->getUserSongInteractions(User::find($candidateId));
            
            // Calculate Jaccard similarity
            $similarity = $this->calculateJaccardSimilarity($userSongs, $candidateSongs);
            
            if ($similarity >= self::SIMILARITY_THRESHOLD) {
                $similarities[$candidateId] = $similarity;
            }
        }
        
        // Return top similar users
        return $similarities->sortDesc()->take(self::MAX_SIMILAR_USERS);
    }
    
    /**
     * Calculate Jaccard similarity between two users
     */
    private function calculateJaccardSimilarity(Collection $user1Songs, Collection $user2Songs): float
    {
        $user1Set = $user1Songs->keys();
        $user2Set = $user2Songs->keys();
        
        $intersection = $user1Set->intersect($user2Set)->count();
        $union = $user1Set->merge($user2Set)->unique()->count();
        
        if ($union === 0) {
            return 0.0;
        }
        
        return $intersection / $union;
    }
    
    /**
     * Generate recommendations from similar users
     */
    private function generateRecommendationsFromSimilarUsers(
        User $user,
        Collection $similarUsers,
        Collection $userSongs,
        int $limit
    ): Collection {
        $recommendations = collect();
        
        // Get songs from similar users
        foreach ($similarUsers as $similarUserId => $similarity) {
            $similarUserSongs = $this->getUserSongInteractions(User::find($similarUserId));
            
            foreach ($similarUserSongs as $songId => $score) {
                // Skip songs user has already interacted with
                if ($userSongs->has($songId)) {
                    continue;
                }
                
                // Weight score by user similarity
                $weightedScore = $score * $similarity;
                
                if (!$recommendations->has($songId)) {
                    $recommendations[$songId] = 0;
                }
                
                $recommendations[$songId] += $weightedScore;
            }
        }
        
        // Get top recommended song IDs
        $topSongIds = $recommendations->sortDesc()->take($limit)->keys();
        
        // Fetch actual song models with relationships
        return Song::with(['artist', 'album', 'genres'])
            ->whereIn('id', $topSongIds)
            ->where('status', 'approved')
            ->get()
            ->sortBy(function ($song) use ($recommendations) {
                return -$recommendations[$song->id]; // Negative for desc sort
            })
            ->values();
    }
    
    /**
     * Get fallback recommendations (popular songs)
     */
    private function getFallbackRecommendations(int $limit): Collection
    {
        return Cache::tags(['popular', 'songs'])->remember(
            'fallback:recommendations',
            1800,
            fn() => Song::with(['artist', 'album', 'genres'])
                ->where('status', 'approved')
                ->orderByDesc('play_count')
                ->limit($limit)
                ->get()
        );
    }
    
    /**
     * Get item-based recommendations (songs similar to a given song)
     */
    public function getSimilarSongs(Song $song, int $limit = 10): Collection
    {
        return Cache::tags(['songs', "song:{$song->id}"])->remember(
            "song:{$song->id}:similar",
            3600,
            function () use ($song, $limit) {
                return $this->calculateSimilarSongs($song, $limit);
            }
        );
    }
    
    /**
     * Calculate similar songs based on user co-interaction
     */
    private function calculateSimilarSongs(Song $song, int $limit): Collection
    {
        // Get users who interacted with this song
        $usersWhoLikedThis = PlayHistory::where('song_id', $song->id)
            ->distinct('user_id')
            ->pluck('user_id');
        
        if ($usersWhoLikedThis->isEmpty()) {
            return $this->getSimilarSongsByGenre($song, $limit);
        }
        
        // Get songs those users also liked
        $coInteractions = PlayHistory::whereIn('user_id', $usersWhoLikedThis)
            ->where('song_id', '!=', $song->id)
            ->select('song_id', DB::raw('COUNT(DISTINCT user_id) as user_count'))
            ->groupBy('song_id')
            ->orderByDesc('user_count')
            ->limit($limit)
            ->pluck('song_id');
        
        return Song::with(['artist', 'album', 'genres'])
            ->whereIn('id', $coInteractions)
            ->where('status', 'approved')
            ->get();
    }
    
    /**
     * Get similar songs by genre (fallback)
     */
    private function getSimilarSongsByGenre(Song $song, int $limit): Collection
    {
        $genreIds = $song->genres->pluck('id');
        
        if ($genreIds->isEmpty()) {
            return collect();
        }
        
        return Song::with(['artist', 'album', 'genres'])
            ->where('id', '!=', $song->id)
            ->where('status', 'approved')
            ->whereHas('genres', function ($query) use ($genreIds) {
                $query->whereIn('genres.id', $genreIds);
            })
            ->orderByDesc('play_count')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Clear recommendation cache for a user
     */
    public function clearUserCache(User $user): bool
    {
        try {
            Cache::tags(["user:{$user->id}", 'recommendations'])->flush();
            Cache::forget("user:{$user->id}:song_interactions");
            Cache::forget("user:{$user->id}:cf_recommendations");
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to clear recommendation cache', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get recommendation explanation for transparency
     */
    public function getRecommendationExplanation(User $user, Song $song): array
    {
        $userSongs = $this->getUserSongInteractions($user);
        $similarUsers = $this->findSimilarUsers($user, $userSongs);
        
        // Find which similar users listened to this song
        $listenedBy = [];
        foreach ($similarUsers as $similarUserId => $similarity) {
            $similarUserSongs = $this->getUserSongInteractions(User::find($similarUserId));
            
            if ($similarUserSongs->has($song->id)) {
                $listenedBy[] = [
                    'user_id' => $similarUserId,
                    'similarity' => round($similarity, 3),
                ];
            }
        }
        
        return [
            'song_id' => $song->id,
            'song_title' => $song->title,
            'reason' => 'Users similar to you enjoyed this',
            'similar_users_count' => count($listenedBy),
            'your_taste_similarity' => $similarUsers->avg(),
            'confidence' => min(count($listenedBy) / 10, 1.0), // 0-1 scale
        ];
    }
}
