<?php

namespace App\Services;

use App\Models\User;
use App\Models\Activity;
use App\Models\FeedItem;
use App\Models\FeedPreference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeedPreferenceService
{
    /**
     * Mark activity as "not interested" with reason
     */
    public function markNotInterested(User $user, Activity $activity, ?string $reason = null, array $metadata = []): void
    {
        FeedPreference::create([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'preference_type' => 'not_interested',
            'reason' => $reason,
            'metadata' => $metadata,
        ]);

        // Clear user's feed cache to refresh immediately
        $this->clearUserFeedCache($user);

        // Learn from feedback patterns
        $this->learnFromFeedback($user, $activity, $reason);
    }

    /**
     * Mark FeedItem as "not interested" with reason
     */
    public function markNotInterestedItem(User $user, FeedItem $item, ?string $reason = null, array $metadata = []): void
    {
        try {
            if (!class_exists(\App\Models\FeedPreference::class)) {
                return; // Model not yet created
            }
            
            FeedPreference::create([
                'user_id' => $user->id,
                'feed_item_id' => $item->id,
                'preference_type' => 'not_interested',
                'reason' => $reason,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('FeedPreference storage failed', ['error' => $e->getMessage()]);
        }

        $this->clearUserFeedCache($user);
    }

    /**
     * Hide activity from feed
     */
    public function hideActivity(User $user, Activity $activity): void
    {
        FeedPreference::create([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'preference_type' => 'hidden',
        ]);

        $this->clearUserFeedCache($user);
    }

    /**
     * Hide FeedItem from feed
     */
    public function hideItem(User $user, FeedItem $item): void
    {
        try {
            if (!class_exists(\App\Models\FeedPreference::class)) {
                return;
            }
            
            FeedPreference::create([
                'user_id' => $user->id,
                'feed_item_id' => $item->id,
                'preference_type' => 'hidden',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('FeedPreference storage failed', ['error' => $e->getMessage()]);
        }

        $this->clearUserFeedCache($user);
    }

    /**
     * Save activity for later
     */
    public function saveActivity(User $user, Activity $activity): void
    {
        FeedPreference::firstOrCreate([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
        ], [
            'preference_type' => 'saved',
        ]);
    }

    /**
     * Save FeedItem for later
     */
    public function saveItem(User $user, FeedItem $item): void
    {
        try {
            if (!class_exists(\App\Models\FeedPreference::class)) {
                return;
            }
            
            FeedPreference::firstOrCreate([
                'user_id' => $user->id,
                'feed_item_id' => $item->id,
            ], [
                'preference_type' => 'saved',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('FeedPreference storage failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Unsave FeedItem
     */
    public function unsaveItem(User $user, FeedItem $item): bool
    {
        try {
            if (!class_exists(\App\Models\FeedPreference::class)) {
                return true;
            }
            
            return FeedPreference::where('user_id', $user->id)
                ->where('feed_item_id', $item->id)
                ->where('preference_type', 'saved')
                ->delete() > 0;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('FeedPreference storage failed', ['error' => $e->getMessage()]);
            return true;
        }
    }

    /**
     * Get IDs of saved FeedItems for user
     */
    public function getSavedItemIds(User $user): array
    {
        try {
            if (!class_exists(\App\Models\FeedPreference::class)) {
                return [];
            }
            
            return FeedPreference::where('user_id', $user->id)
                ->where('preference_type', 'saved')
                ->whereNotNull('feed_item_id')
                ->pluck('feed_item_id')
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Undo "not interested" or "hidden" preference
     */
    public function undoPreference(User $user, Activity $activity): bool
    {
        $deleted = FeedPreference::where('user_id', $user->id)
            ->where('activity_id', $activity->id)
            ->whereIn('preference_type', ['not_interested', 'hidden'])
            ->delete();

        if ($deleted) {
            $this->clearUserFeedCache($user);
        }

        return $deleted > 0;
    }

    /**
     * Get user's feed preferences
     */
    public function getUserPreferences(User $user): array
    {
        try {
            if (!class_exists(\App\Models\FeedPreference::class)) {
                return $this->getEmptyPreferences();
            }
            
            $cacheKey = "user:{$user->id}:feed_preferences";
            
            // Use regular cache if tags not supported
            if (!$this->supportsTagging()) {
                return Cache::remember($cacheKey, 3600, fn() => $this->buildPreferences($user));
            }
            
            return Cache::tags(['user', $user->id])->remember(
                $cacheKey,
                3600,
                fn() => $this->buildPreferences($user)
            );
        } catch (\Throwable $e) {
            return $this->getEmptyPreferences();
        }
    }

    /**
     * Check if cache driver supports tagging
     */
    protected function supportsTagging(): bool
    {
        $driver = config('cache.default');
        return in_array($driver, ['redis', 'memcached', 'array']);
    }

    /**
     * Build preferences from database
     */
    protected function buildPreferences(User $user): array
    {
        try {
            return [
                'not_interested_count' => FeedPreference::where('user_id', $user->id)
                    ->where('preference_type', 'not_interested')
                    ->count(),
                'hidden_count' => FeedPreference::where('user_id', $user->id)
                    ->where('preference_type', 'hidden')
                    ->count(),
                'saved_count' => FeedPreference::where('user_id', $user->id)
                    ->where('preference_type', 'saved')
                    ->count(),
                'top_reasons' => $this->getTopNotInterestedReasons($user),
            ];
        } catch (\Throwable $e) {
            return $this->getEmptyPreferences();
        }
    }

    /**
     * Get empty preferences structure
     */
    protected function getEmptyPreferences(): array
    {
        return [
            'not_interested_count' => 0,
            'hidden_count' => 0,
            'saved_count' => 0,
            'top_reasons' => [],
        ];
    }

    /**
     * Update user's feed preferences
     */
    public function updatePreferences(User $user, array $preferences): void
    {
        // Store preferences in user metadata or dedicated table
        // For now, just clear cache to allow new preferences to take effect
        $this->clearUserFeedCache($user);
    }

    /**
     * Get top reasons for "not interested" feedback
     */
    protected function getTopNotInterestedReasons(User $user): array
    {
        return FeedPreference::where('user_id', $user->id)
            ->where('preference_type', 'not_interested')
            ->whereNotNull('reason')
            ->select('reason', DB::raw('COUNT(*) as count'))
            ->groupBy('reason')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'reason')
            ->toArray();
    }

    /**
     * Learn from user feedback to improve future recommendations
     */
    protected function learnFromFeedback(User $user, Activity $activity, ?string $reason): void
    {
        if (!$reason) {
            return;
        }

        // Update user's content preferences based on feedback
        switch ($reason) {
            case 'not_relevant':
                // Reduce weight of this activity category
                $this->adjustCategoryPreference($user, $activity, -0.1);
                break;

            case 'seen_too_often':
                // Increase penalty for similar content
                $this->adjustContentFrequency($user, $activity, -0.2);
                break;

            case 'dont_like_artist':
                // Consider soft-blocking this artist
                $this->adjustArtistPreference($user, $activity, -0.3);
                break;

            case 'dont_like_genre':
                // Reduce genre weight in personalization
                $this->adjustGenrePreference($user, $activity, -0.2);
                break;

            case 'inappropriate':
                // Flag for moderation review
                $this->flagForReview($activity);
                break;
        }

        // Clear preference cache to apply changes
        Cache::tags(['user', $user->id])->forget("user:{$user->id}:feed_preferences");
    }

    /**
     * Adjust user's preference for content category
     */
    protected function adjustCategoryPreference(User $user, Activity $activity, float $adjustment): void
    {
        $category = $this->getActivityCategory($activity);
        $settings = $user->feedSettings;

        if (!$settings) {
            $settings = $user->feedSettings()->create([
                'show_social_activities' => true,
                'show_event_updates' => true,
                'show_sacco_updates' => true,
                'show_store_promotions' => true,
                'show_platform_announcements' => true,
            ]);
        }

        // Store adjustment in metadata for algorithm to use
        $metadata = $settings->metadata ?? [];
        $metadata['category_weights'] = $metadata['category_weights'] ?? [];
        $metadata['category_weights'][$category] =
            ($metadata['category_weights'][$category] ?? 1.0) + $adjustment;

        // Don't let weight go below 0
        $metadata['category_weights'][$category] = max(0, $metadata['category_weights'][$category]);

        $settings->update(['metadata' => $metadata]);
    }

    /**
     * Adjust content frequency penalty
     */
    protected function adjustContentFrequency(User $user, Activity $activity, float $adjustment): void
    {
        $contentType = $activity->subject_type;
        $settings = $user->feedSettings;

        if (!$settings) {
            return;
        }

        $metadata = $settings->metadata ?? [];
        $metadata['frequency_penalties'] = $metadata['frequency_penalties'] ?? [];
        $metadata['frequency_penalties'][$contentType] =
            ($metadata['frequency_penalties'][$contentType] ?? 0) + $adjustment;

        $settings->update(['metadata' => $metadata]);
    }

    /**
     * Adjust artist preference
     */
    protected function adjustArtistPreference(User $user, Activity $activity, float $adjustment): void
    {
        if ($activity->actor_type !== 'Artist') {
            return;
        }

        $settings = $user->feedSettings;
        if (!$settings) {
            return;
        }

        $metadata = $settings->metadata ?? [];
        $metadata['artist_penalties'] = $metadata['artist_penalties'] ?? [];
        $metadata['artist_penalties'][$activity->actor_id] =
            ($metadata['artist_penalties'][$activity->actor_id] ?? 0) + $adjustment;

        $settings->update(['metadata' => $metadata]);
    }

    /**
     * Adjust genre preference
     */
    protected function adjustGenrePreference(User $user, Activity $activity, float $adjustment): void
    {
        if (!in_array($activity->subject_type, ['Song', 'Album'])) {
            return;
        }

        $subject = $activity->subject;
        if (!$subject || !isset($subject->genre_id)) {
            return;
        }

        $settings = $user->feedSettings;
        if (!$settings) {
            return;
        }

        $genreFilters = $settings->genre_filters ?? [];

        // If adjustment is negative and strong enough, add to filter list
        if ($adjustment < -0.15 && !in_array($subject->genre_id, $genreFilters)) {
            $genreFilters[] = $subject->genre_id;
            $settings->update(['genre_filters' => $genreFilters]);
        }
    }

    /**
     * Flag content for moderation review
     */
    protected function flagForReview(Activity $activity): void
    {
        // Create moderation flag (assuming you have a moderation system)
        try {
            if (class_exists('App\\Models\\ModerationFlag')) {
                $moderationFlagClass = 'App\\Models\\ModerationFlag';
                $moderationFlagClass::create([
                    'flaggable_type' => Activity::class,
                    'flaggable_id' => $activity->id,
                    'reason' => 'inappropriate_from_feed',
                    'status' => 'pending',
                    'priority' => 'medium',
                ]);
            }
        } catch (\Exception $e) {
            // Log the issue but don't fail the entire operation
            Log::warning('Failed to create moderation flag: ' . $e->getMessage());
        }
    }

    /**
     * Get activity category
     */
    protected function getActivityCategory(Activity $activity): string
    {
        $action = $activity->action;

        if (str_contains($action, 'song') || str_contains($action, 'album') || str_contains($action, 'playlist')) {
            return 'music';
        }
        if (str_contains($action, 'event')) {
            return 'events';
        }
        if (str_contains($action, 'sacco') || str_contains($action, 'loan') || str_contains($action, 'dividend')) {
            return 'sacco';
        }
        if (str_contains($action, 'follow') || str_contains($action, 'friend') || str_contains($action, 'comment')) {
            return 'social';
        }
        if (str_contains($action, 'product') || str_contains($action, 'store')) {
            return 'store';
        }

        return 'platform';
    }

    /**
     * Clear user's feed cache
     */
    protected function clearUserFeedCache(User $user): void
    {
        Cache::tags(['feed', "user:{$user->id}"])->flush();
    }

    /**
     * Get feedback analytics for admin dashboard
     */
    public function getFeedbackAnalytics(int $days = 30): array
    {
        $since = now()->subDays($days);

        return [
            'total_feedback' => FeedPreference::where('created_at', '>', $since)->count(),
            'by_type' => FeedPreference::where('created_at', '>', $since)
                ->select('preference_type', DB::raw('COUNT(*) as count'))
                ->groupBy('preference_type')
                ->pluck('count', 'preference_type')
                ->toArray(),
            'by_reason' => FeedPreference::where('created_at', '>', $since)
                ->whereNotNull('reason')
                ->select('reason', DB::raw('COUNT(*) as count'))
                ->groupBy('reason')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'reason')
                ->toArray(),
            'most_hidden_activities' => $this->getMostHiddenActivities($days),
        ];
    }

    /**
     * Get most hidden/not interested activities
     */
    protected function getMostHiddenActivities(int $days): array
    {
        return FeedPreference::where('created_at', '>', now()->subDays($days))
            ->whereIn('preference_type', ['not_interested', 'hidden'])
            ->with('activity')
            ->select('activity_id', DB::raw('COUNT(*) as hide_count'))
            ->groupBy('activity_id')
            ->orderByDesc('hide_count')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'activity_id' => $item->activity_id,
                    'hide_count' => $item->hide_count,
                    'activity' => $item->activity ? [
                        'action' => $item->activity->action,
                        'actor' => $item->activity->actor?->name,
                        'created_at' => $item->activity->created_at,
                    ] : null,
                ];
            })
            ->toArray();
    }
}
