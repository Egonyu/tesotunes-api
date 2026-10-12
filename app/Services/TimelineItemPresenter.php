<?php

namespace App\Services;

use App\Models\Event;
use App\Models\FeedItem;
use App\Models\Modules\Forum\Poll;
use App\Models\Song;
use App\Modules\Podcast\Models\PodcastEpisode;
use App\Modules\Promotions\Models\PromotionRequest;
use App\Modules\Store\Models\Product;

class TimelineItemPresenter
{
    public function __construct(private TimelinePreferenceService $preferences) {}

    public function present(FeedItem $item): array
    {
        $subject = $item->subject;
        $title = $item->title;
        $body = $item->body;
        $actor = ['id' => $item->actor_id, 'name' => $item->actor_name ?? 'TesoTunes', 'type' => $item->actor_type ?? 'platform', 'avatar_url' => $item->actor_avatar_url, 'verified' => (bool) $item->actor_verified];
        $presentation = ['kind' => 'update', 'title' => $item->title ?? 'Community update', 'href' => $item->actions[0]['url'] ?? null, 'image' => $item->media_thumbnail_url ?? $item->media_url];
        if ($subject instanceof Song) {
            $presentation = ['kind' => 'song', 'title' => $subject->title, 'href' => '/songs/'.$subject->slug, 'image' => $subject->artwork_url, 'song_id' => $subject->id, 'subtitle' => $subject->artist?->stage_name, 'genre' => $subject->primaryGenre?->name, 'duration_seconds' => $subject->duration_seconds];
            $actor['href'] = $subject->artist?->slug ? '/artists/'.$subject->artist->slug : null;
        } elseif ($subject instanceof PodcastEpisode) {
            $presentation = ['kind' => 'podcast', 'title' => $subject->title, 'subtitle' => $subject->podcast?->title, 'href' => '/podcasts/'.$subject->podcast_id.'/episodes/'.$subject->id, 'image' => $subject->artwork ?? $subject->podcast?->artwork, 'duration_seconds' => $subject->duration_seconds, 'episode_number' => $subject->episode_number];
        } elseif ($subject instanceof Poll) {
            $presentation = ['kind' => 'poll', 'title' => $subject->title, 'href' => '/polls/'.$subject->id, 'poll_id' => $subject->id, 'ends_at' => $subject->ends_at?->toIso8601String()];
            if ($subject->is_anonymous) {
                $actor = ['id' => null, 'name' => 'Community poll', 'type' => 'community', 'avatar_url' => null, 'verified' => false];
                $title = $subject->title;
                $body = $subject->description;
            }
        } elseif ($subject instanceof Event) {
            $presentation = ['kind' => 'event', 'title' => $subject->title, 'href' => '/events/'.$subject->slug, 'image' => $subject->featured_image_url, 'starts_at' => $subject->starts_at?->toIso8601String(), 'ends_at' => $subject->ends_at?->toIso8601String(), 'location' => implode(' · ', array_filter([$subject->venue_name, $subject->city])), 'is_free' => (bool) $subject->is_free];
        } elseif ($subject instanceof Product) {
            $presentation = ['kind' => 'product', 'title' => $subject->name, 'href' => '/store/products/'.$subject->slug, 'image' => $subject->featured_image_url, 'subtitle' => $subject->store?->name, 'price_ugx' => $subject->price_ugx !== null ? (float) $subject->price_ugx : null, 'price_credits' => $subject->price_credits !== null ? (int) $subject->price_credits : null];
        } elseif ($subject instanceof PromotionRequest) {
            $presentation = ['kind' => 'promotion', 'title' => $subject->title, 'href' => '/promotions/requests/'.$subject->uuid, 'budget_min_ugx' => $subject->budget_min_ugx !== null ? (float) $subject->budget_min_ugx : null, 'budget_max_ugx' => $subject->budget_max_ugx !== null ? (float) $subject->budget_max_ugx : null, 'deadline_at' => $subject->deadline_at?->toIso8601String(), 'platforms' => array_values(array_filter($subject->target_platforms ?? [], 'is_string'))];
        } elseif (isset($item->extras['song_id']) && $item->type === 'song_release') {
            $presentation = array_merge($presentation, ['kind' => 'song', 'song_id' => (int) $item->extras['song_id'], 'duration_seconds' => $item->media_duration_seconds]);
        }

        return [
            'id' => $item->id, 'uuid' => $item->uuid, 'identity' => $this->preferences->identity($item), 'source' => 'feed_item',
            'type' => $item->type, 'module' => $item->module, 'title' => $title, 'body' => $body,
            'actor' => $actor, 'presentation' => $presentation,
            'media' => $item->media_url ? ['type' => $item->media_type, 'url' => $item->media_url, 'thumbnail_url' => $item->media_thumbnail_url ?? $item->media_url, 'duration_seconds' => $item->media_duration_seconds] : null,
            'engagement' => ['likes' => $item->likes_count ?? 0, 'comments' => $item->comments_count ?? 0, 'shares' => $item->shares_count ?? 0, 'views' => $item->views_count ?? 0],
            'tags' => $item->tags ?? [], 'actions' => $item->actions ?? [], 'extras' => $item->extras ?? [],
            'is_prestige' => (bool) $item->is_prestige, 'has_celebration' => (bool) $item->has_celebration,
            'is_aggregated' => (bool) $item->is_aggregated, 'aggregation_count' => $item->aggregation_count ?? 1,
            'published_at' => $item->published_at?->toIso8601String(), 'expires_at' => $item->expires_at?->toIso8601String(),
        ];
    }
}
