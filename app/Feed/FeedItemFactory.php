<?php

namespace App\Feed;

use App\DTOs\Feed\FeedItem as FeedItemDTO;
use App\Models\Activity;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

/**
 * Factory for converting Activity records into FeedItem DTOs.
 *
 * Called by FeedService::getLegacy() to bridge the Activity table
 * into the ranked feed pipeline.
 */
class FeedItemFactory
{
    /**
     * Convert an Activity model into a FeedItem DTO.
     *
     * Uses FeedItemService::createFromActivity() if no feed_item exists yet,
     * otherwise converts the activity data directly to a DTO for display.
     */
    public static function fromActivity(Activity $activity): ?FeedItemDTO
    {
        $mapping = static::activityTypeMap();
        $type = $activity->type;

        if (! isset($mapping[$type])) {
            Log::debug("FeedItemFactory: unmapped activity type '{$type}'");
            return null;
        }

        $map = $mapping[$type];
        $actor = $activity->user ?? $activity->actor;
        $subject = $activity->subject;

        $actorName = $actor?->name ?? $actor?->stage_name ?? 'Someone';
        $subjectName = $subject?->title ?? $subject?->name ?? $subject?->stage_name ?? '';

        $headline = str_replace(
            [':actor', ':subject'],
            [$actorName, $subjectName],
            $map['headline'] ?? ':actor did something'
        );

        $media = [];
        if ($subject) {
            $mediaUrl = static::resolveMediaUrl($subject);
            if ($mediaUrl) {
                $media = [
                    'type' => static::resolveMediaType($subject),
                    'url'  => $mediaUrl,
                    'thumbnail_url' => $mediaUrl,
                ];
            }
        }

        $engagement = [
            'likes'    => $activity->properties['likes'] ?? 0,
            'comments' => $activity->properties['comments'] ?? 0,
            'shares'   => $activity->properties['shares'] ?? 0,
            'views'    => $activity->properties['views'] ?? 0,
        ];

        $actions = static::resolveActions($subject);
        $tags = static::resolveTags($activity, $subject);

        return new FeedItemDTO(
            id: $activity->id,
            uuid: $activity->uuid ?? "act-{$activity->id}",
            type: $map['feed_type'],
            module: $map['module'],
            title: $headline,
            body: $activity->description,
            actor: [
                'id'         => $actor?->id,
                'name'       => $actorName,
                'avatar_url' => $actor?->avatar_url ?? $actor?->profile_photo_url,
                'verified'   => (bool) ($actor?->is_verified ?? false),
                'type'       => $map['actor_type'] ?? 'user',
            ],
            media: $media,
            engagement: $engagement,
            tags: $tags,
            actions: $actions,
            extras: array_merge(
                $activity->properties ?? [],
                ['activity_id' => $activity->id, 'source' => 'activity_bridge']
            ),
            isPrestige: $map['is_prestige'] ?? false,
            publishedAt: $activity->created_at?->toIso8601String(),
        );
    }

    /**
     * Map Activity type strings → feed metadata.
     */
    protected static function activityTypeMap(): array
    {
        return [
            // Music
            'uploaded_song'     => ['feed_type' => 'song_release',      'module' => 'music',  'headline' => ':actor released a new track: :subject', 'actor_type' => 'artist'],
            'distributed_song'  => ['feed_type' => 'song_release',      'module' => 'music',  'headline' => ':actor distributed ":subject"', 'actor_type' => 'artist'],
            'featured_song'     => ['feed_type' => 'song_release',      'module' => 'music',  'headline' => ':actor\'s ":subject" is now featured!', 'actor_type' => 'artist', 'is_prestige' => true],
            'released_album'    => ['feed_type' => 'album_release',     'module' => 'music',  'headline' => ':actor dropped a new album: :subject', 'actor_type' => 'artist'],
            'created_playlist'  => ['feed_type' => 'playlist_created',  'module' => 'music',  'headline' => ':actor created a playlist: :subject'],

            // Events
            'created_event'     => ['feed_type' => 'event_created',     'module' => 'events', 'headline' => 'New event: :subject'],
            'event_announced'   => ['feed_type' => 'event_created',     'module' => 'events', 'headline' => 'Event announced: :subject'],

            // Social
            'liked_song'        => ['feed_type' => 'user_activity',     'module' => 'social', 'headline' => ':actor liked ":subject"'],
            'liked_post'        => ['feed_type' => 'user_activity',     'module' => 'social', 'headline' => ':actor liked a post'],
            'commented_song'    => ['feed_type' => 'user_activity',     'module' => 'social', 'headline' => ':actor commented on ":subject"'],
            'followed_artist'   => ['feed_type' => 'user_followed',     'module' => 'social', 'headline' => ':actor followed :subject'],
            'followed_user'     => ['feed_type' => 'user_followed',     'module' => 'social', 'headline' => ':actor followed :subject'],
            'shared_song'       => ['feed_type' => 'shared_content',    'module' => 'social', 'headline' => ':actor shared ":subject"'],

            // Awards
            'voted_award'       => ['feed_type' => 'award_voted',       'module' => 'awards', 'headline' => ':actor voted in the awards'],
            'award_voting_opened'    => ['feed_type' => 'award_season_started', 'module' => 'awards', 'headline' => 'Award voting is now open!', 'is_prestige' => true],
            'nomination_announced'   => ['feed_type' => 'nomination_submitted', 'module' => 'awards', 'headline' => ':subject has been nominated!'],
            'award_winner_announced' => ['feed_type' => 'award_won',    'module' => 'awards', 'headline' => ':subject won the award!', 'is_prestige' => true],

            // Store
            'product_listed'    => ['feed_type' => 'product_reviewed',  'module' => 'store',  'headline' => 'New product: :subject'],
            'store_created'     => ['feed_type' => 'store_created',     'module' => 'store',  'headline' => ':actor opened a new store'],

            // SACCO
            'sacco_dividend_declared' => ['feed_type' => 'dividend_received', 'module' => 'sacco', 'headline' => 'Dividends declared!'],
            'sacco_member_joined'     => ['feed_type' => 'sacco_joined',      'module' => 'sacco', 'headline' => ':actor joined the SACCO'],
            'sacco_milestone_reached' => ['feed_type' => 'sacco_milestone',   'module' => 'sacco', 'headline' => 'SACCO milestone reached!'],

            // Ojokotau
            'ojokotau_campaign_launched' => ['feed_type' => 'campaign_created',   'module' => 'ojokotau', 'headline' => 'New campaign: :subject'],
            'ojokotau_goal_reached'      => ['feed_type' => 'campaign_milestone', 'module' => 'ojokotau', 'headline' => ':subject reached its goal!'],

            // Loyalty
            'loyalty_card_launched'     => ['feed_type' => 'fan_club_joined',    'module' => 'loyalty', 'headline' => ':actor launched a loyalty card'],
            'loyalty_tier_upgrade'      => ['feed_type' => 'points_milestone',   'module' => 'loyalty', 'headline' => ':actor leveled up!'],
            'loyalty_reward_available'  => ['feed_type' => 'reward_redeemed',    'module' => 'loyalty', 'headline' => 'New reward available!'],

            // Forum
            'forum_topic_created' => ['feed_type' => 'thread_created', 'module' => 'forum', 'headline' => ':actor started a discussion: :subject'],
            'poll_created'        => ['feed_type' => 'poll_created',   'module' => 'forum', 'headline' => 'New poll: :subject'],
        ];
    }

    protected static function resolveMediaType($subject): ?string
    {
        if (! $subject) return null;

        return match (true) {
            $subject instanceof \App\Models\Song     => 'song',
            $subject instanceof \App\Models\Album    => 'album',
            $subject instanceof \App\Models\Event    => 'image',
            $subject instanceof \App\Models\Playlist => 'playlist',
            default => null,
        };
    }

    protected static function resolveMediaUrl($subject): ?string
    {
        if (! $subject) return null;

        return match (true) {
            $subject instanceof \App\Models\Song  => $subject->artwork_url ?? $subject->cover_url ?? null,
            $subject instanceof \App\Models\Album => $subject->artwork_url ?? $subject->cover_url ?? null,
            $subject instanceof \App\Models\Event => $subject->banner_url ?? $subject->image_url ?? null,
            default => null,
        };
    }

    protected static function resolveActions($subject): array
    {
        if (! $subject) return [];

        $actions = [];

        if ($subject instanceof \App\Models\Song) {
            $actions[] = ['type' => 'play',  'label' => 'Play',       'url' => "/songs/{$subject->slug}"];
            $actions[] = ['type' => 'view',  'label' => 'View',       'url' => "/songs/{$subject->slug}"];
        } elseif ($subject instanceof \App\Models\Album) {
            $actions[] = ['type' => 'view',  'label' => 'View Album', 'url' => "/albums/{$subject->slug}"];
        } elseif ($subject instanceof \App\Models\Event) {
            $actions[] = ['type' => 'view',  'label' => 'View Event', 'url' => "/events/{$subject->slug}"];
        }

        return $actions;
    }

    protected static function resolveTags(Activity $activity, $subject): array
    {
        $tags = [];

        if ($subject && method_exists($subject, 'primaryGenre') && $subject->primaryGenre) {
            $tags[] = $subject->primaryGenre->name;
        }

        if (isset($activity->properties['genre'])) {
            $tags[] = $activity->properties['genre'];
        }

        return array_unique($tags);
    }

    /**
     * Magic call for backward compatibility — any undefined static method returns null.
     */
    public function __call($method, $parameters)
    {
        return null;
    }
}
