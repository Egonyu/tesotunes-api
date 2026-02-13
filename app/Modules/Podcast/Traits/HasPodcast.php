<?php

namespace App\Modules\Podcast\Traits;

use App\Modules\Podcast\Models\Podcast;
use App\Modules\Podcast\Models\PodcastCollaborator;
use App\Modules\Podcast\Models\PodcastSubscription;
use App\Modules\Podcast\Models\PodcastListen;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasPodcast
{
    /**
     * Get all podcasts owned by this user
     */
    public function ownedPodcasts(): HasMany
    {
        return $this->hasMany(Podcast::class);
    }

    /**
     * Get all podcasts this user collaborates on
     */
    public function collaboratedPodcasts(): HasMany
    {
        return $this->hasMany(PodcastCollaborator::class);
    }

    /**
     * Get all podcast subscriptions for this user
     */
    public function podcastSubscriptions(): HasMany
    {
        return $this->hasMany(PodcastSubscription::class);
    }

    /**
     * Get podcast listening history for this user
     */
    public function podcastListens(): HasMany
    {
        return $this->hasMany(PodcastListen::class);
    }

    /**
     * Check if user owns a specific podcast
     */
    public function ownsPodcast(Podcast $podcast): bool
    {
        return $this->ownedPodcasts()->where('id', $podcast->id)->exists();
    }

    /**
     * Check if user is subscribed to a specific podcast
     */
    public function isSubscribedToPodcast(Podcast $podcast): bool
    {
        return $this->podcastSubscriptions()
            ->where('podcast_id', $podcast->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Check if user is a collaborator on a specific podcast
     */
    public function isCollaboratorOnPodcast(Podcast $podcast): bool
    {
        return $this->collaboratedPodcasts()
            ->where('podcast_id', $podcast->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Subscribe to a podcast
     */
    public function subscribeToPodcast(Podcast $podcast): PodcastSubscription
    {
        return $this->podcastSubscriptions()->updateOrCreate([
            'podcast_id' => $podcast->id,
        ], [
            'status' => 'active',
            'subscribed_at' => now(),
        ]);
    }

    /**
     * Unsubscribe from a podcast
     */
    public function unsubscribeFromPodcast(Podcast $podcast): void
    {
        $this->podcastSubscriptions()
            ->where('podcast_id', $podcast->id)
            ->update([
                'status' => 'cancelled',
                'unsubscribed_at' => now(),
            ]);
    }

    /**
     * Get total podcast listening time for this user
     */
    public function getTotalPodcastListeningTime(): int
    {
        return $this->podcastListens()->sum('listen_duration') ?? 0;
    }

    /**
     * Get favorite podcast categories based on listening history
     */
    public function getFavoritePodcastCategories(int $limit = 5): array
    {
        return $this->podcastListens()
            ->join('podcast_episodes', 'podcast_listens.episode_id', '=', 'podcast_episodes.id')
            ->join('podcasts', 'podcast_episodes.podcast_id', '=', 'podcasts.id')
            ->join('podcast_categories', 'podcasts.category_id', '=', 'podcast_categories.id')
            ->groupBy('podcast_categories.id', 'podcast_categories.name')
            ->orderByRaw('SUM(podcast_listens.listen_duration) DESC')
            ->limit($limit)
            ->pluck('podcast_categories.name', 'podcast_categories.id')
            ->toArray();
    }
}