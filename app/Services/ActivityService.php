<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ActivityService
{
    /**
     * Log an activity
     *
     * @param User|null $actor The user performing the action
     * @param string $action The action being performed (uploaded_song, created_event, etc.)
     * @param Model $subject The subject of the action (Song, Event, Album, etc.)
     * @param array $metadata Additional data about the activity
     * @param string $actorType The type of actor (User, Artist, System)
     * @return Activity|null
     */
    public static function log(
        ?User $actor,
        string $action,
        Model $subject,
        array $metadata = [],
        string $actorType = 'User'
    ): ?Activity {
        // Don't log if no actor (system actions can be logged separately)
        if (!$actor && $actorType !== 'System') {
            return null;
        }

        // Check user privacy settings
        if ($actor && !self::shouldLogActivity($actor, $action)) {
            return null;
        }

        // Create the activity
        $activity = Activity::create([
            'user_id' => $actor?->id,
            'type' => $action,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->id,
            'data' => $metadata,
        ]);

        // Clear user's feed cache
        if ($actor) {
            self::clearUserFeedCache($actor->id);
            
            // Clear feed cache for followers
            self::clearFollowersFeedCache($actor);
        }

        return $activity;
    }

    /**
     * Log a system activity (no specific actor)
     * Note: System activities are currently skipped as the activities table requires a user_id
     *
     * @param string $action
     * @param Model $subject
     * @param array $metadata
     * @return Activity|null
     */
    public static function logSystem(
        string $action,
        Model $subject,
        array $metadata = []
    ): ?Activity {
        // Skip system activities as the activities table requires a non-null user_id
        // In the future, this could use a designated system user or a separate table
        return null;
    }

    /**
     * Check if activity should be logged based on user settings
     *
     * @param User $user
     * @param string $action
     * @return bool
     */
    private static function shouldLogActivity(User $user, string $action): bool
    {
        // Check if user has disabled activity tracking
        if (!($user->settings->show_activity ?? true)) {
            return false;
        }

        // Check action-specific settings
        $privateActions = $user->settings->private_actions ?? [];
        if (in_array($action, $privateActions)) {
            return false;
        }

        return true;
    }

    /**
     * Clear user's feed cache
     *
     * @param int $userId
     * @return void
     */
    private static function clearUserFeedCache(int $userId): void
    {
        \App\Helpers\CacheHelper::flush(['feed', "user:{$userId}"]);
    }

    /**
     * Clear feed cache for all followers of the user
     *
     * @param User $user
     * @return void
     */
    private static function clearFollowersFeedCache(User $user): void
    {
        // Get all users following this user
        $followerIds = $user->followers()->pluck('id');
        
        foreach ($followerIds as $followerId) {
            self::clearUserFeedCache($followerId);
        }
    }

    /**
     * Get recent activities for a user (their own activities)
     *
     * @param User $user
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUserActivities(User $user, int $limit = 20)
    {
        return Activity::where('user_id', $user->id)
            ->with(['subject', 'actor'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get activities from users that the given user follows
     *
     * @param User $user
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getFollowingActivities(User $user, int $limit = 20)
    {
        $followingIds = $user->following()->pluck('id')->toArray();
        
        if (empty($followingIds)) {
            return collect([]);
        }

        return Activity::whereIn('user_id', $followingIds)
            ->with(['subject', 'actor'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get activities of a specific type
     *
     * @param string $action
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActivitiesByAction(string $action, int $limit = 20)
    {
        return Activity::where('activity_type', $action)
            ->with(['subject', 'actor'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Delete old activities (cleanup job)
     *
     * @param int $daysOld
     * @return int Number of deleted activities
     */
    public static function deleteOldActivities(int $daysOld = 90): int
    {
        return Activity::where('created_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Get activity counts by action type
     *
     * @param User $user
     * @param int $days
     * @return array
     */
    public static function getActivityCounts(User $user, int $days = 7): array
    {
        return Activity::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('activity_type, COUNT(*) as count')
            ->groupBy('activity_type')
            ->pluck('count', 'activity_type')
            ->toArray();
    }

    /**
     * Increment engagement count (likes, comments, shares)
     *
     * @param Activity $activity
     * @param string $type (likes, comments, shares)
     * @param int $increment
     * @return void
     */
    public static function incrementEngagement(Activity $activity, string $type, int $increment = 1): void
    {
        $field = $type . '_count';
        
        if (in_array($field, ['likes_count', 'comments_count', 'shares_count'])) {
            $activity->increment($field, $increment);
        }
    }
}
