<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\FeedItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Feed Item Service
 *
 * Centralised factory for creating feed_items records.
 * Every module observer calls this service instead of writing
 * to Activities directly. Activities remain an audit log;
 * feed_items is the display layer.
 *
 * Design principles:
 * - Config-driven base weights (config/feed.php → feed_types)
 * - Unknown types degrade gracefully to GenericFeed
 * - Aggregation hash prevents feed spam
 * - Privacy: financial modules default to 'members'
 */
class FeedItemService
{
    // ── Base weights per feed type (Uganda-tuned) ──────────────

    protected static array $baseWeights = [
        'song_release' => 120,
        'album_release' => 130,
        'playlist_created' => 50,
        'song_milestone' => 90,
        'artist_update' => 60,
        'artist_joined' => 40,
        'event_created' => 100,
        'event_reminder' => 80,
        'ticket_purchased' => 30,
        'event_attended' => 40,
        'product_purchased' => 30,
        'product_reviewed' => 25,
        'store_created' => 50,
        'sacco_joined' => 40,
        'loan_taken' => 20,
        'loan_repaid' => 20,
        'dividend_received' => 60,
        'sacco_milestone' => 70,
        'fan_club_joined' => 40,
        'reward_redeemed' => 35,
        'points_milestone' => 50,
        'nomination_submitted' => 80,
        'award_won' => 140,
        'award_season_started' => 100,
        'award_voted' => 30,
        'thread_created' => 45,
        'reply_posted' => 20,
        'poll_created' => 60,
        'poll_ended' => 50,
        'episode_published' => 90,
        'podcast_milestone' => 70,
        'campaign_created' => 80,
        'campaign_funded' => 70,
        'campaign_milestone' => 90,
        'promotion_started' => 55,
        'promotion_featured' => 65,
        'announcement' => 90,
        'user_followed' => 15,
        'comment_posted' => 10,
        'shared_content' => 15,
    ];

    // ── Uganda context modifiers ───────────────────────────────

    protected static array $ugandaModifiers = [
        'artist_is_ugandan' => 20,
        'same_region' => 15,
        'same_genre' => 25,
        'trending_locally' => 30,
        'user_follows_artist' => 40,
        'user_interacted_before' => 20,
        'older_than_7_days' => -50,
    ];

    // ── Module → visibility defaults ───────────────────────────

    protected static array $privacyDefaults = [
        'music' => 'public',
        'events' => 'public',
        'awards' => 'public',
        'forum' => 'public',
        'podcasts' => 'public',
        'loyalty' => 'public',
        'store' => 'members',   // Never expose individual buyers
        'sacco' => 'members',   // Financial privacy
        'ojokotau' => 'public',
        'platform' => 'public',
    ];

    // ── Anti-spam: max items per actor per 24 h per type ───────

    protected static int $maxPerActorPerDay = 5;

    // ═══════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create a feed item from structured data.
     *
     * This is the ONLY public entry point modules should use.
     */
    public static function create(array $data): ?FeedItem
    {
        $type = $data['type'] ?? 'generic';
        $module = $data['module'] ?? 'platform';
        $actorId = $data['actor_id'] ?? null;

        // Anti-spam: check rate limit per actor + type
        if ($actorId && ! static::canPublish($actorId, $type)) {
            Log::info("FeedItemService: rate-limited actor {$actorId} for type {$type}");

            return null;
        }

        // Aggregation hash: same actor + type + subject within 24 h
        $aggHash = static::aggregationHash($data);
        $existing = static::findAggregatable($aggHash);

        if ($existing) {
            return static::aggregate($existing, $data);
        }

        $baseScore = static::baseWeight($type);
        $visibility = $data['visibility'] ?? static::$privacyDefaults[$module] ?? 'public';

        $feedItem = FeedItem::create([
            'uuid' => $data['uuid'] ?? Str::uuid()->toString(),
            'type' => $type,
            'module' => $module,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'actor_id' => $actorId,
            'actor_type' => $data['actor_type'] ?? 'user',
            'actor_name' => $data['actor_name'] ?? null,
            'actor_avatar_url' => $data['actor_avatar_url'] ?? null,
            'actor_verified' => $data['actor_verified'] ?? false,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'media_type' => $data['media_type'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'media_thumbnail_url' => $data['media_thumbnail_url'] ?? null,
            'media_duration_seconds' => $data['media_duration_seconds'] ?? null,
            'visibility' => $visibility,
            'base_rank_boost' => $baseScore,
            'is_prestige' => $data['is_prestige'] ?? false,
            'has_celebration' => $data['has_celebration'] ?? false,
            'is_aggregated' => false,
            'aggregation_count' => 1,
            'region' => $data['region'] ?? null,
            'language' => $data['language'] ?? null,
            'tags' => $data['tags'] ?? [],
            'actions' => $data['actions'] ?? [],
            'extras' => $data['extras'] ?? [],
            'published_at' => $data['published_at'] ?? now(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        // Bust feed caches for followers
        if ($actorId) {
            static::bustFollowerCaches($actorId);
        }

        return $feedItem;
    }

    /**
     * Create a feed item from an existing Activity record (bridge).
     */
    public static function createFromActivity(Activity $activity): ?FeedItem
    {
        $mapping = static::activityTypeMapping();
        $actType = $activity->type;

        if (! isset($mapping[$actType])) {
            Log::debug("FeedItemService: no mapping for activity type '{$actType}'");

            return null;
        }

        $map = $mapping[$actType];
        $actor = $activity->user;
        $subject = $activity->subject;

        return static::create([
            'type' => $map['feed_type'],
            'module' => $map['module'],
            'title' => static::buildHeadline($map, $activity),
            'body' => $activity->description ?? $map['summary_template'] ?? null,
            'actor_id' => $actor?->id,
            'actor_type' => $map['actor_type'] ?? 'user',
            'actor_name' => $actor?->name ?? $actor?->stage_name ?? 'TesoTunes',
            'actor_avatar_url' => $actor?->avatar_url ?? $actor?->profile_photo_url ?? null,
            'actor_verified' => (bool) ($actor?->is_verified ?? false),
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'media_type' => static::resolveMediaType($subject),
            'media_url' => static::resolveMediaUrl($subject),
            'media_thumbnail_url' => static::resolveMediaThumbnail($subject),
            'is_prestige' => $map['is_prestige'] ?? false,
            'has_celebration' => $map['has_celebration'] ?? false,
            'tags' => static::resolveTags($activity, $subject),
            'actions' => static::resolveActions($map, $subject),
            'extras' => array_merge(
                $activity->properties ?? [],
                ['activity_id' => $activity->id]
            ),
        ]);
    }

    /**
     * Create a feed item directly from a Post (called when user creates a post).
     */
    public static function createFromPost(\App\Models\Post $post): ?FeedItem
    {
        $author = $post->user;
        $media = $post->media->first();
        $song = $post->song;

        return static::create([
            'type' => 'user_post',
            'module' => 'social',
            'title' => null,
            'body' => $post->content,
            'actor_id' => $author?->id,
            'actor_type' => 'user',
            'actor_name' => $author?->name,
            'actor_avatar_url' => $author?->avatar_url ?? $author?->profile_photo_url,
            'actor_verified' => (bool) ($author?->is_verified ?? false),
            'subject_type' => \App\Models\Post::class,
            'subject_id' => $post->id,
            'media_type' => $media?->type ?? ($song ? 'song' : null),
            'media_url' => $media?->url ?? $song?->artwork_url,
            'media_thumbnail_url' => $media?->thumbnail_url ?? $song?->artwork_url,
            'visibility' => $post->visibility ?? 'public',
            'tags' => [],
            'extras' => [
                'post_id' => $post->id,
                'song_id' => $song?->id,
                'song_title' => $song?->title,
                'artist_name' => $song?->artist?->stage_name,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // AGGREGATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate a deterministic aggregation hash.
     *
     * Same actor + same type + same subject_type within 24 h → aggregate.
     */
    protected static function aggregationHash(array $data): string
    {
        $parts = [
            $data['actor_id'] ?? 'system',
            $data['type'] ?? 'generic',
            $data['subject_type'] ?? 'none',
            now()->format('Y-m-d'),
        ];

        return md5(implode('|', $parts));
    }

    /**
     * Find an existing feed item that can be aggregated into.
     */
    protected static function findAggregatable(string $hash): ?FeedItem
    {
        return FeedItem::where('extras->aggregation_hash', $hash)
            ->where('published_at', '>=', now()->subDay())
            ->first();
    }

    /**
     * Aggregate data into an existing feed item.
     */
    protected static function aggregate(FeedItem $existing, array $newData): FeedItem
    {
        $count = ($existing->aggregation_count ?? 1) + 1;
        $extras = $existing->extras ?? [];

        // Store aggregated subject IDs
        $aggregatedIds = $extras['aggregated_subject_ids'] ?? [$existing->subject_id];
        if (isset($newData['subject_id']) && ! in_array($newData['subject_id'], $aggregatedIds)) {
            $aggregatedIds[] = $newData['subject_id'];
        }

        $extras['aggregated_subject_ids'] = $aggregatedIds;
        $extras['aggregation_hash'] = static::aggregationHash($newData);

        // Update headline for aggregation
        $actorName = $existing->actor_name ?? 'Someone';
        $typeLabel = static::typeLabel($existing->type);

        $existing->update([
            'title' => "{$actorName} {$typeLabel} ({$count})",
            'is_aggregated' => true,
            'aggregation_count' => $count,
            'extras' => $extras,
            'published_at' => now(), // Bump to top
        ]);

        return $existing->fresh();
    }

    // ═══════════════════════════════════════════════════════════════
    // ACTIVITY TYPE → FEED TYPE MAPPING
    // ═══════════════════════════════════════════════════════════════

    /**
     * Maps Activity::type values to feed item configuration.
     */
    protected static function activityTypeMapping(): array
    {
        return [
            // Music
            'uploaded_song' => [
                'feed_type' => 'song_release',
                'module' => 'music',
                'headline' => ':actor released a new track: :subject',
                'actor_type' => 'artist',
            ],
            'distributed_song' => [
                'feed_type' => 'song_release',
                'module' => 'music',
                'headline' => ':actor distributed ":subject" to streaming platforms',
                'actor_type' => 'artist',
            ],
            'featured_song' => [
                'feed_type' => 'song_release',
                'module' => 'music',
                'headline' => '🔥 :actor\'s ":subject" is now featured!',
                'actor_type' => 'artist',
                'is_prestige' => true,
            ],
            'released_album' => [
                'feed_type' => 'album_release',
                'module' => 'music',
                'headline' => '🎶 :actor just dropped a new album: :subject',
                'actor_type' => 'artist',
                'has_celebration' => true,
            ],
            'created_playlist' => [
                'feed_type' => 'playlist_created',
                'module' => 'music',
                'headline' => ':actor created a new playlist: :subject',
            ],

            // Events
            'created_event' => [
                'feed_type' => 'event_created',
                'module' => 'events',
                'headline' => '📅 New event: :subject',
            ],
            'event_announced' => [
                'feed_type' => 'event_created',
                'module' => 'events',
                'headline' => '📢 Event announced: :subject',
            ],

            // Social
            'liked_song' => [
                'feed_type' => 'user_activity',
                'module' => 'social',
                'headline' => ':actor liked the song ":subject"',
            ],
            'liked_post' => [
                'feed_type' => 'user_activity',
                'module' => 'social',
                'headline' => ':actor liked a post',
            ],
            'commented_song' => [
                'feed_type' => 'user_activity',
                'module' => 'social',
                'headline' => ':actor commented on ":subject"',
            ],
            'followed_artist' => [
                'feed_type' => 'user_followed',
                'module' => 'social',
                'headline' => ':actor started following :subject',
            ],
            'followed_user' => [
                'feed_type' => 'user_followed',
                'module' => 'social',
                'headline' => ':actor started following :subject',
            ],
            'shared_song' => [
                'feed_type' => 'shared_content',
                'module' => 'social',
                'headline' => ':actor shared the song ":subject"',
            ],

            // Awards
            'voted_award' => [
                'feed_type' => 'award_voted',
                'module' => 'awards',
                'headline' => ':actor voted in the awards',
            ],

            // Loyalty
            'joined_fan_club' => [
                'feed_type' => 'fan_club_joined',
                'module' => 'loyalty',
                'headline' => ':actor joined a fan club',
            ],
            'redeemed_reward' => [
                'feed_type' => 'reward_redeemed',
                'module' => 'loyalty',
                'headline' => ':actor redeemed a reward',
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════

    protected static function baseWeight(string $type): float
    {
        $configWeights = config('feed.feed_types.base_weights', []);

        return $configWeights[$type]
            ?? static::$baseWeights[$type]
            ?? 30; // Unknown types get minimal weight
    }

    protected static function canPublish(int $actorId, string $type): bool
    {
        $key = "feed_rate:{$actorId}:{$type}:".now()->format('Y-m-d');
        $count = Cache::get($key, 0);

        if ($count >= static::$maxPerActorPerDay) {
            return false;
        }

        Cache::put($key, $count + 1, now()->endOfDay());

        return true;
    }

    protected static function bustFollowerCaches(int $actorId): void
    {
        $cacheDriver = config('cache.default');
        $supportsTagging = in_array($cacheDriver, ['redis', 'memcached', 'array']);

        if ($supportsTagging) {
            Cache::tags(['feed'])->flush();
        }
    }

    protected static function buildHeadline(array $map, Activity $activity): string
    {
        $headline = $map['headline'] ?? ':actor did something';
        $actorName = $activity->user?->name ?? $activity->user?->stage_name ?? 'Someone';
        $subjectName = $activity->subject?->title
            ?? $activity->subject?->name
            ?? $activity->subject?->stage_name
            ?? $activity->properties['song_title']
            ?? $activity->properties['event_title']
            ?? '';

        return str_replace(
            [':actor', ':subject'],
            [$actorName, $subjectName],
            $headline
        );
    }

    protected static function resolveMediaType($subject): ?string
    {
        if (! $subject) {
            return null;
        }

        return match (true) {
            $subject instanceof \App\Models\Song => 'song',
            $subject instanceof \App\Models\Album => 'album',
            $subject instanceof \App\Models\Event => 'image',
            $subject instanceof \App\Models\Playlist => 'playlist',
            default => null,
        };
    }

    protected static function resolveMediaUrl($subject): ?string
    {
        if (! $subject) {
            return null;
        }

        return match (true) {
            $subject instanceof \App\Models\Song => $subject->artwork_url ?? null,
            $subject instanceof \App\Models\Album => $subject->artwork_url ?? null,
            $subject instanceof \App\Models\Event => $subject->banner_url ?? $subject->image_url ?? null,
            $subject instanceof \App\Models\Playlist => $subject->artwork_url ?? null,
            default => null,
        };
    }

    protected static function resolveMediaThumbnail($subject): ?string
    {
        return static::resolveMediaUrl($subject);
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

    protected static function resolveActions(array $map, $subject): array
    {
        if (! $subject) {
            return [];
        }

        $actions = [];

        if ($subject instanceof \App\Models\Song) {
            $actions[] = ['type' => 'play', 'label' => 'Play', 'url' => "/songs/{$subject->slug}"];
            $actions[] = ['type' => 'view', 'label' => 'View', 'url' => "/songs/{$subject->slug}"];
        } elseif ($subject instanceof \App\Models\Album) {
            $actions[] = ['type' => 'view', 'label' => 'View Album', 'url' => "/albums/{$subject->slug}"];
        } elseif ($subject instanceof \App\Models\Event) {
            $actions[] = ['type' => 'view', 'label' => 'View Event', 'url' => "/events/{$subject->slug}"];
            $actions[] = ['type' => 'register', 'label' => 'Interested', 'url' => "/events/{$subject->slug}"];
        }

        return $actions;
    }

    protected static function typeLabel(string $type): string
    {
        return match ($type) {
            'song_release' => 'released new tracks',
            'album_release' => 'dropped albums',
            'event_created' => 'announced events',
            'user_post' => 'posted updates',
            'user_activity' => 'was active',
            'user_followed' => 'made connections',
            'shared_content' => 'shared content',
            default => 'was active',
        };
    }
}
