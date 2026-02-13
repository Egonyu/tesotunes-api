<?php

namespace App\Services;

use App\Models\FeedItem as FeedItemModel;
use App\Models\User;
use App\DTOs\Feed\FeedItem as FeedItemDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Feed Ranking Service
 * 
 * Ranks feed items using a composite scoring algorithm:
 * - Recency (exponential decay)
 * - Relevance (followed actors, genres)
 * - Engagement (likes, comments, shares)
 * - Diversity (prevent clustering)
 * - Prestige (award winners, milestones)
 * - Personalization (user preferences)
 */
class FeedRankingService
{
    protected array $weights;
    protected array $config;
    protected bool $supportsTagging;

    public function __construct()
    {
        $this->config = config('feed.ranking', [
            'weights' => [
                'recency' => 0.35,
                'relevance' => 0.25,
                'engagement' => 0.15,
                'diversity' => 0.10,
                'personalization' => 0.10,
                'prestige' => 0.05,
            ],
            'recency' => [
                'half_life_hours' => 24,
                'max_days' => 14,
            ],
            'engagement' => [
                'viral_threshold' => 1000,
                'popular_threshold' => 200,
                'trending_threshold' => 50,
            ],
        ]);
        $this->weights = $this->config['weights'];

        $cacheDriver = config('cache.default');
        $this->supportsTagging = in_array($cacheDriver, ['redis', 'memcached', 'array']);
    }

    // ═══════════════════════════════════════════════════════════════
    // MAIN RANKING METHODS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Rank a collection of FeedItem models
     */
    public function rankFeedItems(?User $user, Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        // Get user-specific weights (A/B testing)
        if ($user) {
            $this->weights = $this->getVariantWeights($user);
        }

        // Calculate scores
        $scored = $items->map(function (FeedItemModel $item) use ($user) {
            $item->feed_score = $this->calculateScore($item, $user);
            return $item;
        });

        // Sort by score descending
        return $scored->sortByDesc('feed_score')->values();
    }

    /**
     * Rank a collection of FeedItem DTOs
     */
    public function rankDTOs(?User $user, Collection $dtos): Collection
    {
        if ($dtos->isEmpty()) {
            return $dtos;
        }

        if ($user) {
            $this->weights = $this->getVariantWeights($user);
        }

        // Calculate scores for DTOs
        $scored = $dtos->map(function (FeedItemDTO $dto) use ($user) {
            $dto->rankScore = $this->calculateDTOScore($dto, $user);
            return $dto;
        });

        return $scored->sortByDesc('rankScore')->values();
    }

    // ═══════════════════════════════════════════════════════════════
    // SCORING FOR FEEDITEM MODEL
    // ═══════════════════════════════════════════════════════════════

    /**
     * Calculate composite score for FeedItem model
     */
    protected function calculateScore(FeedItemModel $item, ?User $user): float
    {
        $recencyScore = $this->calculateRecencyScore($item->published_at ?? $item->created_at);
        $relevanceScore = $this->calculateRelevanceScore($item, $user);
        $engagementScore = $this->calculateEngagementScore($item);
        $diversityScore = $this->calculateDiversityScore($item->module);
        $personalizationScore = $this->calculatePersonalizationScore($item, $user);
        $prestigeScore = $this->calculatePrestigeScore($item);

        // Weighted sum
        $score = (
            $recencyScore * $this->weights['recency'] +
            $relevanceScore * $this->weights['relevance'] +
            $engagementScore * $this->weights['engagement'] +
            $diversityScore * $this->weights['diversity'] +
            $personalizationScore * $this->weights['personalization'] +
            $prestigeScore * ($this->weights['prestige'] ?? 0.05)
        );

        // Apply base rank boost from config
        $score += $item->base_rank_boost;

        // Apply penalties
        $score -= $this->calculatePenalties($item, $user);

        return max(0, $score);
    }

    /**
     * Calculate composite score for DTO
     */
    protected function calculateDTOScore(FeedItemDTO $dto, ?User $user): float
    {
        $recencyScore = $this->calculateRecencyScore($dto->createdAt);
        $relevanceScore = $this->calculateDTORelevanceScore($dto, $user);
        $engagementScore = $this->calculateDTOEngagementScore($dto);
        $diversityScore = $this->calculateDiversityScore($dto->context?->module ?? 'general');
        $prestigeScore = ($dto->meta?->isPrestige ?? false) ? 100 : 0;

        $score = (
            $recencyScore * $this->weights['recency'] +
            $relevanceScore * $this->weights['relevance'] +
            $engagementScore * $this->weights['engagement'] +
            $diversityScore * $this->weights['diversity'] +
            $prestigeScore * ($this->weights['prestige'] ?? 0.05)
        );

        // Apply rank boost from DTO meta
        $score += $dto->meta?->rankBoost ?? 0;

        return max(0, $score);
    }

    // ═══════════════════════════════════════════════════════════════
    // INDIVIDUAL SCORE COMPONENTS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Recency score (exponential decay)
     */
    protected function calculateRecencyScore(?Carbon $timestamp): float
    {
        if (!$timestamp) {
            return 50; // Default for items without timestamp
        }

        $ageHours = $timestamp->diffInHours(now());
        $halfLife = $this->config['recency']['half_life_hours'] ?? 24;
        $maxDays = $this->config['recency']['max_days'] ?? 14;

        // Don't show content older than max_days
        if ($ageHours > $maxDays * 24) {
            return 0;
        }

        // Exponential decay
        $score = pow(2, -($ageHours / $halfLife));

        return $score * 100; // Scale to 0-100
    }

    /**
     * Relevance score for FeedItem model
     */
    protected function calculateRelevanceScore(FeedItemModel $item, ?User $user): float
    {
        if (!$user) {
            return 30; // Base score for guests
        }

        $score = 0;

        // Check if user follows the actor
        if ($item->actor_id && $item->actor_type) {
            $isFollowing = $this->isFollowing($user, $item->actor_id, $item->actor_type);
            if ($isFollowing) {
                $score += 70;
            }
        }

        // Check if item is from user's preferred genres/tags
        if ($item->tags) {
            $userPreferences = $this->getUserPreferences($user);
            $matchingTags = array_intersect($item->tags, $userPreferences['tags'] ?? []);
            $score += count($matchingTags) * 10;
        }

        // Boost for same region
        if ($item->region && $this->getUserRegion($user) === $item->region) {
            $score += 15;
        }

        return min(100, $score);
    }

    /**
     * Relevance score for DTO
     */
    protected function calculateDTORelevanceScore(FeedItemDTO $dto, ?User $user): float
    {
        if (!$user) {
            return 30;
        }

        $score = 0;

        // Check if user follows actor
        if ($dto->actor?->id && $dto->actor?->type) {
            $isFollowing = $this->isFollowing($user, $dto->actor->id, $dto->actor->type);
            if ($isFollowing) {
                $score += 70;
            }
        }

        // Check tags
        if ($dto->context?->tags) {
            $userPreferences = $this->getUserPreferences($user);
            $matchingTags = array_intersect($dto->context->tags, $userPreferences['tags'] ?? []);
            $score += count($matchingTags) * 10;
        }

        return min(100, $score);
    }

    /**
     * Engagement score for FeedItem model
     */
    protected function calculateEngagementScore(FeedItemModel $item): float
    {
        $thresholds = $this->config['engagement'];
        
        $total = $item->likes_count + $item->comments_count + $item->shares_count;

        if ($total >= ($thresholds['viral_threshold'] ?? 1000)) {
            return 100;
        }
        if ($total >= ($thresholds['popular_threshold'] ?? 200)) {
            return 70;
        }
        if ($total >= ($thresholds['trending_threshold'] ?? 50)) {
            return 40;
        }

        // Linear scale
        $trending = $thresholds['trending_threshold'] ?? 50;
        return min(40, ($total / $trending) * 40);
    }

    /**
     * Engagement score for DTO
     */
    protected function calculateDTOEngagementScore(FeedItemDTO $dto): float
    {
        if (!$dto->engagement) {
            return 0;
        }

        $thresholds = $this->config['engagement'];
        $total = ($dto->engagement->likesCount ?? 0) + 
                 ($dto->engagement->commentsCount ?? 0) + 
                 ($dto->engagement->sharesCount ?? 0);

        if ($total >= ($thresholds['viral_threshold'] ?? 1000)) {
            return 100;
        }
        if ($total >= ($thresholds['popular_threshold'] ?? 200)) {
            return 70;
        }
        if ($total >= ($thresholds['trending_threshold'] ?? 50)) {
            return 40;
        }

        $trending = $thresholds['trending_threshold'] ?? 50;
        return min(40, ($total / $trending) * 40);
    }

    /**
     * Diversity score based on module
     */
    protected function calculateDiversityScore(string $module): float
    {
        // Preferred module distribution
        $preferredMix = [
            'music' => 0.35,
            'events' => 0.15,
            'awards' => 0.10,
            'store' => 0.10,
            'ojokotau' => 0.10,
            'loyalty' => 0.05,
            'sacco' => 0.05,
            'forum' => 0.10,
        ];

        return ($preferredMix[$module] ?? 0.05) * 100;
    }

    /**
     * Personalization score
     */
    protected function calculatePersonalizationScore(FeedItemModel $item, ?User $user): float
    {
        if (!$user) {
            return 0;
        }

        $score = 0;
        $preferences = $this->getUserPreferences($user);

        // Genre matching (for music items)
        if ($item->module === 'music' && !empty($preferences['genres'])) {
            $itemGenre = $item->getExtra('genre');
            if ($itemGenre && in_array($itemGenre, $preferences['genres'])) {
                $score += 50;
            }
        }

        // Artist affinity
        if ($item->actor_id) {
            $artistAffinity = $this->getArtistAffinity($user, $item->actor_id);
            $score += $artistAffinity * 50;
        }

        return min(100, $score);
    }

    /**
     * Prestige score (award winners, milestones)
     */
    protected function calculatePrestigeScore(FeedItemModel $item): float
    {
        if ($item->is_prestige) {
            return 100;
        }
        
        if ($item->has_celebration) {
            return 50;
        }

        // Awards module gets base boost
        if ($item->module === 'awards') {
            return 30;
        }

        return 0;
    }

    /**
     * Calculate penalties
     */
    protected function calculatePenalties(FeedItemModel $item, ?User $user): float
    {
        $penalty = 0;

        // Penalty for already-seen content (if tracking)
        if ($user && $this->hasUserSeen($user, $item->id)) {
            $penalty += 30;
        }

        // Penalty for aggregated content (less novel)
        if ($item->is_aggregated) {
            $penalty += 10;
        }

        return $penalty;
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Check if user follows an actor
     */
    protected function isFollowing(User $user, int $actorId, string $actorType): bool
    {
        $cacheKey = "user:{$user->id}:follows:{$actorType}:{$actorId}";

        $get = function () use ($user, $actorId, $actorType) {
            // Safely check if following relationship exists
            if (!method_exists($user, 'following')) {
                return false;
            }

            $followableType = match($actorType) {
                'Artist' => 'App\Models\Artist',
                'User' => 'App\Models\User',
                default => null,
            };

            if (!$followableType) {
                return false;
            }

            try {
                return $user->following()
                    ->where('followable_id', $actorId)
                    ->where('followable_type', $followableType)
                    ->exists();
            } catch (\Throwable) {
                // Table or columns may not exist in test environment
                return false;
            }
        };

        if ($this->supportsTagging) {
            return Cache::tags(['user', $user->id])->remember($cacheKey, 3600, $get);
        }

        return Cache::remember($cacheKey, 3600, $get);
    }

    /**
     * Get user preferences (cached)
     */
    protected function getUserPreferences(User $user): array
    {
        $cacheKey = "user:{$user->id}:feed_preferences";

        $get = function () use ($user) {
            // Safely get genres - method may not exist on all User implementations
            $genres = [];
            if (method_exists($user, 'preferredGenres') && is_callable([$user, 'preferredGenres'])) {
                try {
                    $genres = $user->preferredGenres()->pluck('id')->toArray();
                } catch (\Throwable) {
                    $genres = [];
                }
            }

            return [
                'genres' => $genres,
                'tags' => $user->preferredTags ?? [],
                'region' => $user->region ?? null,
            ];
        };

        if ($this->supportsTagging) {
            return Cache::tags(['user', $user->id])->remember($cacheKey, 3600, $get);
        }

        return Cache::remember($cacheKey, 3600, $get);
    }

    /**
     * Get user's region
     */
    protected function getUserRegion(User $user): ?string
    {
        return $user->region ?? $user->country ?? null;
    }

    /**
     * Get artist affinity score (0-1)
     */
    protected function getArtistAffinity(User $user, int $actorId): float
    {
        // Could be based on play history, purchases, etc.
        // Simplified: just check if following
        return $this->isFollowing($user, $actorId, 'Artist') ? 1.0 : 0.0;
    }

    /**
     * Check if user has seen this item
     */
    protected function hasUserSeen(User $user, int $itemId): bool
    {
        // Would check view tracking table
        return false;
    }

    /**
     * Get A/B test variant weights
     */
    protected function getVariantWeights(User $user): array
    {
        // Check if user is in A/B test
        $variant = $this->getUserVariant($user);

        return match($variant) {
            'recency_heavy' => array_merge($this->weights, ['recency' => 0.50, 'relevance' => 0.20]),
            'engagement_heavy' => array_merge($this->weights, ['engagement' => 0.30, 'recency' => 0.25]),
            'prestige_heavy' => array_merge($this->weights, ['prestige' => 0.15, 'diversity' => 0.05]),
            default => $this->weights,
        };
    }

    /**
     * Get user's A/B test variant
     */
    protected function getUserVariant(User $user): string
    {
        // Simple hash-based assignment
        $hash = crc32("feed_ranking_{$user->id}");
        $bucket = $hash % 100;

        if ($bucket < 10) return 'recency_heavy';
        if ($bucket < 20) return 'engagement_heavy';
        if ($bucket < 30) return 'prestige_heavy';
        
        return 'control';
    }
}
