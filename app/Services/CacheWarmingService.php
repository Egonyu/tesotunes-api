<?php

namespace App\Services;

use App\Models\User;
use App\Models\Song;
use App\Models\Artist;
use App\Models\Genre;
use App\Helpers\CacheHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cache Warming Service
 * 
 * Proactively warms critical caches to improve initial page load performance
 * Implements intelligent cache warming based on usage patterns
 */
class CacheWarmingService
{
    private FeedService $feedService;
    private FeedRankingService $rankingService;
    
    public function __construct(
        FeedService $feedService,
        FeedRankingService $rankingService
    ) {
        $this->feedService = $feedService;
        $this->rankingService = $rankingService;
    }

    /**
     * Warm all critical caches
     */
    public function warmAll(): array
    {
        $startTime = microtime(true);
        $results = [];

        try {
            $results['trending'] = $this->warmTrendingContent();
            $results['popular'] = $this->warmPopularContent();
            $results['genres'] = $this->warmGenreData();
            $results['artists'] = $this->warmTopArtists();
            $results['discovery'] = $this->warmDiscoveryFeed();

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Cache warming completed', [
                'duration_ms' => $duration,
                'results' => $results
            ]);

            return [
                'success' => true,
                'duration_ms' => $duration,
                'caches_warmed' => array_sum($results),
                'details' => $results
            ];

        } catch (\Exception $e) {
            Log::error('Cache warming failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Warm trending content cache
     */
    public function warmTrendingContent(): int
    {
        $count = 0;
        
        // Trending songs (last 7 days)
        CacheHelper::remember(['trending', 'songs'], 'trending:songs:7days', 900, function () use (&$count) {
            $count++;
            return Song::with(['artist', 'album', 'genres'])
                ->where('created_at', '>=', now()->subDays(7))
                ->where('status', 'approved')
                ->orderByDesc('play_count')
                ->limit(50)
                ->get();
        });
        
        // Trending songs (last 24 hours)
        CacheHelper::remember(['trending', 'songs'], 'trending:songs:24h', 600, function () use (&$count) {
            $count++;
            return Song::with(['artist', 'album', 'genres'])
                ->where('created_at', '>=', now()->subDay())
                ->where('status', 'approved')
                ->orderByDesc('play_count')
                ->limit(30)
                ->get();
        });
        
        // Trending artists
        CacheHelper::remember(['trending', 'artists'], 'trending:artists:7days', 1800, function () use (&$count) {
            $count++;
            return Artist::withCount([
                    'songs as recent_plays' => function($query) {
                        $query->where('created_at', '>=', now()->subDays(7))
                              ->sum('play_count');
                    }
                ])
                ->having('recent_plays', '>', 0)
                ->orderByDesc('recent_plays')
                ->limit(30)
                ->get();
        });
        
        return $count;
    }

    /**
     * Warm popular content cache
     */
    public function warmPopularContent(): int
    {
        $count = 0;
        
        // All-time popular songs
        CacheHelper::remember(['popular', 'songs'], 'popular:songs:alltime', 3600, function () use (&$count) {
            $count++;
            return Song::with(['artist', 'album', 'genres'])
                ->where('status', 'approved')
                ->orderByDesc('play_count')
                ->limit(100)
                ->get();
        });
        
        // Most liked songs
        CacheHelper::remember(['popular', 'songs'], 'popular:songs:liked', 1800, function () use (&$count) {
            $count++;
            return Song::with(['artist', 'album'])
                ->where('status', 'approved')
                ->orderByDesc('likes_count')
                ->limit(50)
                ->get();
        });
        
        // Most downloaded songs
        CacheHelper::remember(['popular', 'songs'], 'popular:songs:downloaded', 1800, function () use (&$count) {
            $count++;
            return Song::with(['artist', 'album'])
                ->where('status', 'approved')
                ->orderByDesc('downloads_count')
                ->limit(50)
                ->get();
        });
        
        return $count;
    }

    /**
     * Warm genre data cache
     */
    public function warmGenreData(): int
    {
        $count = 0;
        
        // All genres with song counts
        CacheHelper::remember(['genres'], 'genres:all:with_counts', 3600, function () use (&$count) {
            $count++;
            return Genre::withCount('songs')
                ->orderBy('name')
                ->get();
        });
        
        // Top genres by plays
        CacheHelper::remember(['genres'], 'genres:top:by_plays', 1800, function () use (&$count) {
            $count++;
            return Genre::select('genres.*')
                ->join('songs', 'genres.id', '=', 'songs.genre_id')
                ->groupBy('genres.id')
                ->orderByRaw('SUM(songs.play_count) DESC')
                ->limit(20)
                ->get();
        });
        
        return $count;
    }

    /**
     * Warm top artists cache
     */
    public function warmTopArtists(): int
    {
        $count = 0;
        
        // Top verified artists
        CacheHelper::remember(['artists'], 'artists:top:verified', 1800, function () use (&$count) {
            $count++;
            return Artist::where('verification_status', 'verified')
                ->withCount('songs')
                ->orderByDesc('monthly_listeners')
                ->limit(50)
                ->get();
        });
        
        // Rising artists (new with good engagement)
        CacheHelper::remember(['artists'], 'artists:rising', 1800, function () use (&$count) {
            $count++;
            return Artist::where('created_at', '>=', now()->subMonths(3))
                ->withCount('songs')
                ->having('songs_count', '>=', 3)
                ->orderByDesc('monthly_listeners')
                ->limit(30)
                ->get();
        });
        
        return $count;
    }

    /**
     * Warm discovery feed cache
     */
    public function warmDiscoveryFeed(): int
    {
        $count = 0;
        
        // Get sample active users and warm their feeds
        $sampleUsers = User::where('status', 'active')
            ->where('last_login_at', '>=', now()->subDays(7))
            ->inRandomOrder()
            ->limit(10)
            ->get();
        
        foreach ($sampleUsers as $user) {
            try {
                $this->feedService->getUserFeed($user->id, 1, 20);
                $count++;
            } catch (\Exception $e) {
                Log::warning('Failed to warm feed for user', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $count;
    }

    /**
     * Clear all warmed caches
     */
    public function clearAll(): bool
    {
        try {
            CacheHelper::flush(['trending']);
            CacheHelper::flush(['popular']);
            CacheHelper::flush(['genres']);
            CacheHelper::flush(['artists']);
            CacheHelper::flush(['feed']);

            Log::info('All warmed caches cleared');
            return true;

        } catch (\Exception $e) {
            Log::error('Failed to clear warmed caches', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
