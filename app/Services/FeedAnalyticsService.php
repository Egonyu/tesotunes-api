<?php

namespace App\Services;

use App\Models\User;
use App\Models\FeedABTest;
use App\Models\FeedAnalytic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FeedAnalyticsService
{
    /**
     * Track feed view event
     */
    public function trackView(?User $user, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return; // Skip tracking for anonymous users
        }
        
        $this->trackEvent($user, null, 'viewed', $feedTab, $metadata);
        
        // Update A/B test metrics
        $this->updateABTestMetrics($user, 'views_count');
    }

    /**
     * Track activity click event
     */
    public function trackClick(?User $user, int $activityId, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return;
        }
        
        $this->trackEvent($user, $activityId, 'clicked', $feedTab, $metadata);
        
        // Update A/B test metrics
        $this->updateABTestMetrics($user, 'clicks_count');
    }

    /**
     * Track activity like event
     */
    public function trackLike(?User $user, int $activityId, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return;
        }
        
        $this->trackEvent($user, $activityId, 'liked', $feedTab, $metadata);
        
        // Update A/B test metrics
        $this->updateABTestMetrics($user, 'engagements_count');
    }

    /**
     * Track activity share event
     */
    public function trackShare(?User $user, int $activityId, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return;
        }
        
        $this->trackEvent($user, $activityId, 'shared', $feedTab, $metadata);
        
        // Update A/B test metrics
        $this->updateABTestMetrics($user, 'engagements_count');
    }

    /**
     * Track activity hidden event
     */
    public function trackHidden(?User $user, int $activityId, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return;
        }
        
        $this->trackEvent($user, $activityId, 'hidden', $feedTab, $metadata);
    }

    /**
     * Track FeedItem hidden event
     */
    public function trackHiddenItem(?User $user, int $itemId, string $feedTab = 'for_you', array $metadata = []): void
    {
        $this->trackHidden($user, $itemId, $feedTab, $metadata);
    }

    /**
     * Track FeedItem click event
     */
    public function trackClickItem(?User $user, int|string $itemId, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return;
        }
        $this->trackEvent($user, is_int($itemId) ? $itemId : null, 'clicked', $feedTab, array_merge($metadata, ['feed_item_id' => $itemId]));
    }

    /**
     * Track FeedItem engagement event
     */
    public function trackEngagementItem(?User $user, int|string $itemId, string $eventType, string $feedTab = 'for_you', array $metadata = []): void
    {
        if (!$user) {
            return;
        }
        
        $this->trackEvent($user, is_int($itemId) ? $itemId : null, $eventType, $feedTab, array_merge($metadata, ['feed_item_id' => $itemId]));
    }

    /**
     * Track generic event
     */
    protected function trackEvent(User $user, ?int $activityId, string $eventType, string $feedTab, array $metadata): void
    {
        try {
            if (!class_exists(\App\Models\FeedAnalytic::class)) {
                return; // Model not yet created
            }
            
            \App\Models\FeedAnalytic::create([
                'user_id' => $user->id,
                'activity_id' => $activityId,
                'event_type' => $eventType,
                'feed_tab' => $feedTab,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            // Fail silently - analytics shouldn't break the feed
            \Illuminate\Support\Facades\Log::debug('Feed analytics tracking failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update A/B test metrics
     */
    protected function updateABTestMetrics(User $user, string $metric): void
    {
        if (!config('feed.ab_testing.enabled')) {
            return;
        }

        try {
            if (!class_exists(\App\Models\FeedABTest::class)) {
                return;
            }
            \App\Models\FeedABTest::where('user_id', $user->id)->increment($metric);
        } catch (\Throwable $e) {
            // Fail silently
        }
    }

    /**
     * Update user session duration for A/B test
     */
    public function updateSessionDuration(User $user, float $duration): void
    {
        if (!config('feed.ab_testing.enabled')) {
            return;
        }

        $abTest = FeedABTest::where('user_id', $user->id)->first();
        
        if ($abTest) {
            // Calculate new average
            $totalSessions = $abTest->views_count;
            $currentTotal = $abTest->avg_session_duration * $totalSessions;
            $newTotal = $currentTotal + $duration;
            $newAverage = $newTotal / ($totalSessions + 1);

            $abTest->update([
                'avg_session_duration' => $newAverage,
            ]);
        }
    }

    /**
     * Get feed analytics dashboard data
     */
    public function getDashboardData(int $days = 7): array
    {
        $since = now()->subDays($days);

        return [
            'overview' => $this->getOverviewMetrics($since),
            'engagement_by_day' => $this->getEngagementByDay($since),
            'engagement_by_tab' => $this->getEngagementByTab($since),
            'top_activities' => $this->getTopActivities($since),
            'ab_test_results' => $this->getABTestResults(),
        ];
    }

    /**
     * Get overview metrics
     */
    protected function getOverviewMetrics(Carbon $since): array
    {
        $total = FeedAnalytic::where('created_at', '>', $since)->count();
        $views = FeedAnalytic::where('created_at', '>', $since)->where('event_type', 'viewed')->count();
        $clicks = FeedAnalytic::where('created_at', '>', $since)->where('event_type', 'clicked')->count();
        $engagements = FeedAnalytic::where('created_at', '>', $since)
            ->whereIn('event_type', ['liked', 'shared'])
            ->count();

        return [
            'total_events' => $total,
            'views' => $views,
            'clicks' => $clicks,
            'engagements' => $engagements,
            'click_through_rate' => $views > 0 ? round(($clicks / $views) * 100, 2) : 0,
            'engagement_rate' => $clicks > 0 ? round(($engagements / $clicks) * 100, 2) : 0,
        ];
    }

    /**
     * Get engagement by day
     */
    protected function getEngagementByDay(Carbon $since): array
    {
        return FeedAnalytic::where('created_at', '>', $since)
            ->select(
                DB::raw('DATE(created_at) as date'),
                'event_type',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date', 'event_type')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(function ($group) {
                return $group->pluck('count', 'event_type')->toArray();
            })
            ->toArray();
    }

    /**
     * Get engagement by feed tab
     */
    protected function getEngagementByTab(Carbon $since): array
    {
        return FeedAnalytic::where('created_at', '>', $since)
            ->whereNotNull('feed_tab')
            ->select('feed_tab', 'event_type', DB::raw('COUNT(*) as count'))
            ->groupBy('feed_tab', 'event_type')
            ->get()
            ->groupBy('feed_tab')
            ->map(function ($group) {
                return $group->pluck('count', 'event_type')->toArray();
            })
            ->toArray();
    }

    /**
     * Get top performing activities
     */
    protected function getTopActivities(Carbon $since, int $limit = 10): array
    {
        return FeedAnalytic::where('created_at', '>', $since)
            ->whereNotNull('activity_id')
            ->with('activity')
            ->select('activity_id', DB::raw('COUNT(*) as engagement_count'))
            ->groupBy('activity_id')
            ->orderByDesc('engagement_count')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'activity_id' => $item->activity_id,
                    'engagement_count' => $item->engagement_count,
                    'activity' => $item->activity ? [
                        'action' => $item->activity->action,
                        'actor' => $item->activity->actor?->name,
                        'created_at' => $item->activity->created_at,
                    ] : null,
                ];
            })
            ->toArray();
    }

    /**
     * Get A/B test results comparison
     */
    public function getABTestResults(): array
    {
        if (!config('feed.ab_testing.enabled')) {
            return ['enabled' => false];
        }

        $variants = config('feed.ab_testing.variants');
        $results = [];

        foreach (array_keys($variants) as $variant) {
            $tests = FeedABTest::where('variant', $variant)->get();

            if ($tests->isEmpty()) {
                continue;
            }

            $totalViews = $tests->sum('views_count');
            $totalClicks = $tests->sum('clicks_count');
            $totalEngagements = $tests->sum('engagements_count');
            $avgSessionDuration = $tests->avg('avg_session_duration');

            $results[$variant] = [
                'name' => $variants[$variant]['name'] ?? $variant,
                'users' => $tests->count(),
                'total_views' => $totalViews,
                'total_clicks' => $totalClicks,
                'total_engagements' => $totalEngagements,
                'click_through_rate' => $totalViews > 0 ? round(($totalClicks / $totalViews) * 100, 2) : 0,
                'engagement_rate' => $totalClicks > 0 ? round(($totalEngagements / $totalClicks) * 100, 2) : 0,
                'avg_session_duration' => round($avgSessionDuration, 2),
            ];
        }

        // Determine winner
        $winner = $this->determineABTestWinner($results);

        return [
            'enabled' => true,
            'variants' => $results,
            'winner' => $winner,
            'statistical_significance' => $this->calculateStatisticalSignificance($results),
        ];
    }

    /**
     * Determine A/B test winner based on engagement rate
     */
    protected function determineABTestWinner(array $results): ?string
    {
        if (empty($results)) {
            return null;
        }

        $minUsers = 100; // Minimum users per variant for valid test
        $validVariants = array_filter($results, fn($r) => $r['users'] >= $minUsers);

        if (empty($validVariants)) {
            return null;
        }

        $winner = null;
        $highestScore = 0;

        foreach ($validVariants as $variant => $data) {
            // Composite score: engagement rate (60%) + CTR (40%)
            $score = ($data['engagement_rate'] * 0.6) + ($data['click_through_rate'] * 0.4);

            if ($score > $highestScore) {
                $highestScore = $score;
                $winner = $variant;
            }
        }

        return $winner;
    }

    /**
     * Calculate statistical significance (Chi-squared test)
     */
    protected function calculateStatisticalSignificance(array $results): string
    {
        if (count($results) < 2) {
            return 'insufficient_data';
        }

        // Simplified significance check - proper implementation would use chi-squared test
        $allVariantsHaveEnoughData = true;
        foreach ($results as $data) {
            if ($data['users'] < 100 || $data['total_views'] < 1000) {
                $allVariantsHaveEnoughData = false;
                break;
            }
        }

        if (!$allVariantsHaveEnoughData) {
            return 'insufficient_data';
        }

        // Check variance in engagement rates
        $engagementRates = array_column($results, 'engagement_rate');
        $variance = $this->calculateVariance($engagementRates);

        if ($variance < 5) {
            return 'not_significant'; // Less than 5% difference
        } elseif ($variance < 15) {
            return 'marginally_significant'; // 5-15% difference
        } else {
            return 'significant'; // More than 15% difference
        }
    }

    /**
     * Calculate variance of an array
     */
    protected function calculateVariance(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        
        return sqrt(array_sum($squaredDiffs) / count($values));
    }

    /**
     * Clear analytics cache
     */
    public function clearCache(): void
    {
        Cache::tags(['feed', 'analytics'])->flush();
    }
}
