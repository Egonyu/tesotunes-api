<?php

namespace App\Observers;

use App\Modules\Podcast\Models\PodcastEpisode;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class PodcastEpisodeObserver
{
    public function created(PodcastEpisode $episode): void
    {
        if ($episode->status === 'published') {
            $this->createFeedItem($episode);
        }
    }

    public function updated(PodcastEpisode $episode): void
    {
        // Episode just published
        if ($episode->isDirty('status') && $episode->status === 'published') {
            $this->createFeedItem($episode);
        }
    }

    protected function createFeedItem(PodcastEpisode $episode): void
    {
        try {
            $podcast = $episode->podcast;
            $user = $podcast?->user;
            $artist = $podcast?->artist;

            if (! $podcast) {
                return;
            }

            $actorName = $artist?->stage_name ?? $user?->name ?? $podcast->author_name ?? 'A podcaster';
            $actorId = $user?->id ?? 0;

            if ($user) {
                ActivityService::log(
                    actor: $user,
                    action: 'published_episode',
                    subject: $episode,
                    metadata: [
                        'episode_title' => $episode->title,
                        'podcast_title' => $podcast->title,
                        'episode_number' => $episode->episode_number,
                    ]
                );
            }

            FeedItemService::create([
                'type' => 'episode_published',
                'module' => 'podcasts',
                'title' => $actorName.' dropped a new episode: '.$episode->title,
                'body' => $episode->description ? substr(strip_tags($episode->description), 0, 200) : null,
                'actor_id' => $actorId,
                'actor_type' => $artist ? 'artist' : 'user',
                'actor_name' => $actorName,
                'actor_avatar_url' => $artist?->avatar_url ?? $user?->avatar_url ?? null,
                'actor_verified' => (bool) ($artist?->is_verified ?? false),
                'subject_type' => PodcastEpisode::class,
                'subject_id' => $episode->id,
                'media_type' => 'song',
                'media_url' => $episode->artwork ?? $podcast->artwork ?? null,
                'media_duration_seconds' => $episode->duration_seconds,
                'tags' => array_filter([$podcast->tags ?? null]),
                'actions' => [
                    ['type' => 'play', 'label' => 'Listen Now', 'url' => "/podcasts/{$podcast->slug}/episodes/{$episode->slug}"],
                ],
                'extras' => [
                    'podcast_title' => $podcast->title,
                    'episode_number' => $episode->episode_number,
                    'season_number' => $episode->season_number,
                    'is_explicit' => $episode->is_explicit,
                    'is_premium' => $episode->is_premium,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('PodcastEpisodeObserver: Failed to create feed item', ['episode_id' => $episode->id, 'error' => $e->getMessage()]);
        }
    }
}
