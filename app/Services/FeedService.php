<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\FeedItem as FeedItemModel;
use App\Models\User;
use App\DTOs\Feed\FeedItem as FeedItemDTO;
use App\Feed\FeedItemFactory;
use App\Feed\TransformerRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

/**
 * Feed Service
 * 
 * New feed service that works with FeedItem model and DTOs.
 * Provides both legacy Activity-based feed and new FeedItem-based feed.
 * 
 * Features:
 * - FeedItem model as primary source
 * - DTO conversion for API responses
 * - Legacy Activity fallback
 * - Visibility/privacy enforcement
 * - Module-based filtering
 * - Ranking integration
 */
class FeedService
{
    protected ?User $user = null;
    protected FeedRankingService $rankingService;
    protected ContentDiversityService $diversityService;
    protected TransformerRegistry $transformerRegistry;
    
    protected array $modules = [];
    protected array $types = [];
    protected array $excludeTypes = [];
    protected array $excludeActors = [];
    
    protected int $perPage;
    protected bool $supportsTagging;
    protected bool $includePrestigeOnly = false;
    protected ?string $region = null;

    public function __construct(
        FeedRankingService $rankingService,
        ContentDiversityService $diversityService,
        TransformerRegistry $transformerRegistry
    ) {
        $this->rankingService = $rankingService;
        $this->diversityService = $diversityService;
        $this->transformerRegistry = $transformerRegistry;
        $this->perPage = config('feed.pagination.per_page', 20);

        // Check if cache driver supports tagging
        $cacheDriver = config('cache.default');
        $this->supportsTagging = in_array($cacheDriver, ['redis', 'memcached', 'array']);
    }

    // ═══════════════════════════════════════════════════════════════
    // BUILDER METHODS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Set the user for feed personalization
     */
    public function forUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Set items per page
     */
    public function perPage(int $perPage): self
    {
        $this->perPage = min($perPage, config('feed.pagination.max_per_page', 50));
        return $this;
    }

    /**
     * Filter by specific modules
     */
    public function forModules(array $modules): self
    {
        $this->modules = $modules;
        return $this;
    }

    /**
     * Filter by specific feed types
     */
    public function ofTypes(array $types): self
    {
        $this->types = $types;
        return $this;
    }

    /**
     * Exclude specific types
     */
    public function excludeTypes(array $types): self
    {
        $this->excludeTypes = $types;
        return $this;
    }

    /**
     * Exclude items from specific actors
     */
    public function excludeActors(array $actorIds): self
    {
        $this->excludeActors = $actorIds;
        return $this;
    }

    /**
     * Only prestige items (awards, milestones)
     */
    public function prestigeOnly(): self
    {
        $this->includePrestigeOnly = true;
        return $this;
    }

    /**
     * Filter by region
     */
    public function forRegion(string $region): self
    {
        $this->region = $region;
        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // PRESET FEEDS
    // ═══════════════════════════════════════════════════════════════

    /**
     * "For You" feed - personalized mix of all content
     */
    public function forYou(): self
    {
        $this->modules = ['music', 'events', 'awards', 'store', 'ojokotau', 'loyalty', 'forum'];
        return $this;
    }

    /**
     * "Following" feed - content from followed artists/users
     */
    public function following(): self
    {
        // Will be filtered in query to only show followed actors
        $this->modules = ['music', 'events', 'awards', 'store', 'ojokotau', 'loyalty'];
        return $this;
    }

    /**
     * "Discover" feed - trending and recommended content
     */
    public function discover(): self
    {
        $this->modules = ['music', 'events', 'awards', 'ojokotau'];
        $this->includePrestigeOnly = false;
        return $this;
    }

    /**
     * "Music" feed - music content only
     */
    public function music(): self
    {
        $this->modules = ['music'];
        return $this;
    }

    /**
     * "Events" feed
     */
    public function events(): self
    {
        $this->modules = ['events'];
        return $this;
    }

    /**
     * "Awards" feed - prestige content
     */
    public function awards(): self
    {
        $this->modules = ['awards'];
        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // MAIN QUERY METHODS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get feed as paginated FeedItem DTOs
     */
    public function get(int $page = 1): LengthAwarePaginator
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey($page);
        $cacheEnabled = config('feed.cache.enabled', true);

        // Try cache first
        if ($cacheEnabled) {
            $cached = $this->getCachedFeed($cacheKey);
            if ($cached !== null) {
                $this->logPerformance('cache_hit', $startTime);
                return $cached;
            }
        }

        // Query feed items
        $feedItems = $this->queryFeedItems();

        // Rank items
        $ranked = $this->rankingService->rankFeedItems($this->user, $feedItems);

        // Apply diversity filter
        $diversified = $this->applyDiversityFilter($ranked);

        // Paginate
        $paginated = $this->paginateItems($diversified, $page);

        // Cache result
        if ($cacheEnabled) {
            $this->cacheFeed($cacheKey, $paginated);
        }

        $this->logPerformance('cache_miss', $startTime);

        return $paginated;
    }

    /**
     * Get feed as paginated FeedItem models (for Blade templates)
     * Unlike get() which returns DTOs, this returns Eloquent models
     */
    public function getModels(int $page = 1): LengthAwarePaginator
    {
        $startTime = microtime(true);

        // Query feed items
        $feedItems = $this->queryFeedItems();

        // Rank items
        $ranked = $this->rankingService->rankFeedItems($this->user, $feedItems);

        // Apply diversity filter
        $diversified = $this->applyDiversityFilter($ranked);

        // Paginate (without converting to DTOs)
        $paginated = $this->paginateModels($diversified, $page);

        $this->logPerformance('models_query', $startTime);

        return $paginated;
    }

    /**
     * Get feed as raw DTOs (no pagination)
     */
    public function getDTOs(int $limit = 50): Collection
    {
        $feedItems = $this->queryFeedItems($limit);
        $ranked = $this->rankingService->rankFeedItems($this->user, $feedItems);
        
        return $ranked->map(fn($item) => $this->itemToDTO($item));
    }

    /**
     * Query FeedItem models from database
     */
    protected function queryFeedItems(?int $limit = null): Collection
    {
        $query = FeedItemModel::query()
            ->published()
            ->visible($this->user);

        // Filter by modules
        if (!empty($this->modules)) {
            $query->whereIn('module', $this->modules);
        }

        // Filter by types
        if (!empty($this->types)) {
            $query->whereIn('type', $this->types);
        }

        // Exclude types
        if (!empty($this->excludeTypes)) {
            $query->whereNotIn('type', $this->excludeTypes);
        }

        // Exclude actors
        if (!empty($this->excludeActors)) {
            $query->whereNotIn('actor_id', $this->excludeActors);
        }

        // Prestige only
        if ($this->includePrestigeOnly) {
            $query->where('is_prestige', true);
        }

        // Region filter
        if ($this->region) {
            $query->forRegion($this->region);
        }

        // Following filter (if user set and following preset used)
        if ($this->user && in_array('following', $this->modules)) {
            $followedIds = $this->getFollowedActorIds();
            if ($followedIds->isNotEmpty()) {
                $query->whereIn('actor_id', $followedIds);
            }
        }

        // Order by ranking
        $query->ranked();

        // Limit
        $limit = $limit ?? ($this->perPage * 5); // Get more than needed for diversity
        $query->limit($limit);

        return $query->get();
    }

    /**
     * Convert FeedItemModel to DTO
     */
    protected function itemToDTO(FeedItemModel $item): FeedItemDTO
    {
        return $item->toDTO($this->user);
    }

    /**
     * Get IDs of actors the user follows
     */
    protected function getFollowedActorIds(): Collection
    {
        if (!$this->user) {
            return collect();
        }

        $cacheKey = "user:{$this->user->id}:followed_actors";
        
        return Cache::remember($cacheKey, 3600, function () {
            return $this->user->following()->pluck('followable_id');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // LEGACY BRIDGE (Activity → FeedItem)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get feed from legacy Activity table (backward compatibility)
     */
    public function getLegacy(int $page = 1): LengthAwarePaginator
    {
        $activities = $this->queryActivities();
        
        // Convert activities to FeedItem DTOs
        $feedItems = $activities->map(function ($activity) {
            try {
                return FeedItemFactory::fromActivity($activity);
            } catch (\Exception $e) {
                Log::warning("Failed to convert activity to DTO: {$activity->id}", [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        })->filter();

        // Rank
        $ranked = $this->rankingService->rankDTOs($this->user, $feedItems);

        // Paginate
        return $this->paginateDTOs($ranked, $page);
    }

    /**
     * Query legacy Activity table
     */
    protected function queryActivities(int $limit = 100): Collection
    {
        $query = Activity::query()
            ->with(['subject', 'actor'])
            ->where('created_at', '>', now()->subDays(14));

        // Module type filtering
        if (!empty($this->modules)) {
            $activityTypes = $this->modulesToActivityTypes($this->modules);
            if (!empty($activityTypes)) {
                $query->whereIn('type', $activityTypes);
            }
        }

        return $query->latest()->limit($limit)->get();
    }

    /**
     * Map modules to legacy activity types
     */
    protected function modulesToActivityTypes(array $modules): array
    {
        $mapping = [
            'music' => ['uploaded_song', 'released_album', 'distributed_song', 'featured_song'],
            'events' => ['created_event', 'event_announced', 'event_reminder'],
            'awards' => ['award_voting_opened', 'nomination_announced', 'award_winner_announced'],
            'store' => ['product_listed', 'store_created', 'commerce_support_milestone'],
            'sacco' => ['sacco_dividend_declared', 'sacco_member_joined', 'sacco_milestone_reached'],
            'ojokotau' => ['ojokotau_campaign_launched', 'ojokotau_goal_reached', 'ojokotau_community_backed'],
            'loyalty' => ['loyalty_card_launched', 'loyalty_tier_upgrade', 'loyalty_reward_available'],
            'forum' => ['forum_topic_created', 'poll_created', 'forum_trending'],
        ];

        $types = [];
        foreach ($modules as $module) {
            if (isset($mapping[$module])) {
                $types = array_merge($types, $mapping[$module]);
            }
        }

        return array_unique($types);
    }

    // ═══════════════════════════════════════════════════════════════
    // DIVERSITY & RANKING
    // ═══════════════════════════════════════════════════════════════

    /**
     * Apply diversity filter to prevent clustering
     */
    protected function applyDiversityFilter(Collection $items): Collection
    {
        // Balance by module
        $balanced = $this->diversityService->balanceByCategory(
            $items,
            fn($item) => $item->module,
            $this->perPage * 3
        );

        // Prevent clustering
        $dispersed = $this->diversityService->preventClustering($balanced, 3);

        return $dispersed;
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGINATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Paginate FeedItem models (converting to DTOs)
     */
    protected function paginateItems(Collection $items, int $page): LengthAwarePaginator
    {
        $total = $items->count();
        $offset = ($page - 1) * $this->perPage;
        
        $pageItems = $items
            ->slice($offset, $this->perPage)
            ->map(fn($item) => $this->itemToDTO($item))
            ->values();

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $this->perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    /**
     * Paginate FeedItem models (keeping as models, for Blade templates)
     */
    protected function paginateModels(Collection $items, int $page): LengthAwarePaginator
    {
        $total = $items->count();
        $offset = ($page - 1) * $this->perPage;
        
        $pageItems = $items
            ->slice($offset, $this->perPage)
            ->values();

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $this->perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    /**
     * Paginate DTOs directly
     */
    protected function paginateDTOs(Collection $dtos, int $page): LengthAwarePaginator
    {
        $total = $dtos->count();
        $offset = ($page - 1) * $this->perPage;
        
        $pageItems = $dtos->slice($offset, $this->perPage)->values();

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $this->perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // CACHING
    // ═══════════════════════════════════════════════════════════════

    protected function getCacheKey(int $page): string
    {
        $userId = $this->user?->id ?? 'guest';
        $modules = implode(',', $this->modules);
        $types = implode(',', $this->types);
        $prestige = $this->includePrestigeOnly ? '1' : '0';
        
        return "feed_v2:{$userId}:m:{$modules}:t:{$types}:p:{$prestige}:page:{$page}";
    }

    protected function getCachedFeed(string $cacheKey): ?LengthAwarePaginator
    {
        $ttl = config('feed.cache.ttl.feed', 300);
        
        $cached = $this->supportsTagging
            ? Cache::tags(['feed', "user:{$this->user?->id}"])->get($cacheKey)
            : Cache::get($cacheKey);

        return $cached;
    }

    protected function cacheFeed(string $cacheKey, LengthAwarePaginator $feed): void
    {
        $ttl = config('feed.cache.ttl.feed', 300);

        if ($this->supportsTagging) {
            Cache::tags(['feed', "user:{$this->user?->id}"])->put($cacheKey, $feed, $ttl);
        } else {
            Cache::put($cacheKey, $feed, $ttl);
        }
    }

    /**
     * Clear feed cache for user
     */
    public function clearCache(): void
    {
        if ($this->supportsTagging && $this->user) {
            Cache::tags(["user:{$this->user->id}", 'feed'])->flush();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PERFORMANCE LOGGING
    // ═══════════════════════════════════════════════════════════════

    protected function logPerformance(string $type, float $startTime): void
    {
        if (!config('feed.analytics.enabled', true)) {
            return;
        }

        $duration = (microtime(true) - $startTime) * 1000;

        Log::channel(config('feed.analytics.log_channel', 'daily'))->info("FeedV2 {$type}", [
            'user_id' => $this->user?->id,
            'duration_ms' => round($duration, 2),
            'modules' => $this->modules,
            'timestamp' => now()->toIso8601String(),
        ]);

        $threshold = config('feed.monitoring.slow_query_threshold', 500);
        if ($duration > $threshold) {
            Log::warning("Slow FeedV2 generation", [
                'user_id' => $this->user?->id,
                'duration_ms' => round($duration, 2),
            ]);
        }
    }
}
