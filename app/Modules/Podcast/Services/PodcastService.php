<?php

namespace App\Modules\Podcast\Services;

use App\Models\Artist;
use App\Models\User;
use App\Modules\Podcast\Models\Podcast;
use App\Modules\Podcast\Models\PodcastEpisode;
use App\Modules\Podcast\Models\PodcastCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class PodcastService
{
    /**
     * Create a new podcast for a user
     */
    public function createPodcast(User $user, array $data): Podcast
    {
        $data['user_id'] = $user->id;
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);

        // Ensure artist_id is set (use user's artist_id or create one if needed)
        if (!isset($data['artist_id']) && $user->artist_id) {
            $data['artist_id'] = $user->artist_id;
        } elseif (!isset($data['artist_id'])) {
                    // If artist_id is not provided, auto-create one
        if (!isset($data['artist_id']) && isset($data['user_id'])) {
            $user = User::find($data['user_id']);
            $stageName = $user->name ?? 'Unknown Artist';
            $artist = Artist::firstOrCreate(
                ['user_id' => $data['user_id']],
                [
                    'stage_name' => $stageName,
                    'slug' => \Illuminate\Support\Str::slug($stageName),
                ]
            );
            $data['artist_id'] = $artist->id;
        }
        }

        return Podcast::create($data);
    }

    /**
     * Update podcast details
     */
    public function updatePodcast(Podcast $podcast, array $data): Podcast
    {
        if (isset($data['title']) && !isset($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $podcast->update($data);
        return $podcast->fresh();
    }

    /**
     * Publish a podcast
     */
    public function publishPodcast(Podcast $podcast): Podcast
    {
        $podcast->update([
            'status' => 'published',
        ]);

        return $podcast->fresh();
    }

    /**
     * Archive a podcast
     */
    public function archivePodcast(Podcast $podcast): Podcast
    {
        $podcast->update(['status' => 'archived']);
        return $podcast->fresh();
    }

    /**
     * Get published podcasts with optional category filter
     */
    public function getPublishedPodcasts(?int $categoryId = null, int $limit = 20): Collection
    {
        $query = Podcast::published()
            ->with(['creator', 'category', 'episodes' => function ($query) {
                $query->published()->latest()->limit(3);
            }])
            ->withCount('episodes')
            ->latest('published_at');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get trending podcasts based on recent activity
     */
    public function getTrendingPodcasts(int $days = 7, int $limit = 10): Collection
    {
        return Podcast::published()
            ->whereHas('listens', function ($query) use ($days) {
                $query->where('created_at', '>=', now()->subDays($days));
            })
            ->withCount(['listens as recent_listens_count' => function ($query) use ($days) {
                $query->where('created_at', '>=', now()->subDays($days));
            }])
            ->with(['creator', 'category'])
            ->orderByDesc('recent_listens_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Search podcasts by title, description, or author
     */
    public function searchPodcasts(string $query, int $limit = 50): Collection
    {
        return Podcast::published()
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%")
                  ->orWhereHas('creator', function ($q) use ($query) {
                      $q->where('name', 'LIKE', "%{$query}%");
                  });
            })
            ->with(['creator', 'category'])
            ->withCount('episodes')
            ->orderByDesc('subscriber_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get podcast recommendations for a user based on their listening history
     */
    public function getRecommendedPodcasts(User $user, int $limit = 10): Collection
    {
        // Get user's favorite categories from listening history
        $favoriteCategories = $user->podcastListens()
            ->join('podcast_episodes', 'podcast_listens.episode_id', '=', 'podcast_episodes.id')
            ->join('podcasts', 'podcast_episodes.podcast_id', '=', 'podcasts.id')
            ->where('podcast_listens.created_at', '>=', now()->subMonth())
            ->groupBy('podcasts.category_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(3)
            ->pluck('podcasts.category_id');

        if ($favoriteCategories->isEmpty()) {
            // If no listening history, return trending podcasts
            return $this->getTrendingPodcasts(limit: $limit);
        }

        // Get podcasts from favorite categories that user hasn't subscribed to
        return Podcast::published()
            ->whereIn('category_id', $favoriteCategories)
            ->whereNotIn('id', $user->podcastSubscriptions()->pluck('podcast_id'))
            ->with(['creator', 'category'])
            ->withCount('episodes')
            ->orderByDesc('subscriber_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Update podcast statistics (called by scheduled job)
     */
    public function updatePodcastStatistics(Podcast $podcast): void
    {
        $totalListens = $podcast->listens()->sum('listen_duration');
        $totalDownloads = $podcast->episodes()->sum('download_count');
        $subscriberCount = $podcast->subscriptions()->where('status', 'active')->count();
        $episodeCount = $podcast->episodes()->published()->count();

        $podcast->update([
            'total_episodes' => $episodeCount,
            'total_listens' => $totalListens,
            'total_downloads' => $totalDownloads,
            'subscriber_count' => $subscriberCount,
        ]);
    }

    /**
     * Check if podcast is eligible for monetization
     */
    public function isEligibleForMonetization(Podcast $podcast): bool
    {
        return $podcast->status === 'published' &&
               $podcast->total_episodes >= 5 &&
               $podcast->subscriber_count >= 100 &&
               $podcast->total_listens >= 1000;
    }

    /**
     * Generate RSS feed URL for podcast
     */
    public function generateRSSFeedUrl(Podcast $podcast): string
    {
        $baseUrl = config('podcast.rss.base_url');
        if (!$baseUrl) {
            return url("/podcast-rss/{$podcast->slug}");
        }
        return "{$baseUrl}/{$podcast->slug}";
    }

    /**
     * Calculate estimated monthly revenue for podcast
     */
    public function calculateEstimatedRevenue(Podcast $podcast): array
    {
        $monthlyListens = $podcast->listens()
            ->where('created_at', '>=', now()->subMonth())
            ->sum('listen_duration');

        $sponsorshipRevenue = $podcast->sponsors()
            ->where('status', 'active')
            ->where('rate_type', 'per_month')
            ->sum('sponsorship_rate');

        $subscriptionRevenue = $podcast->subscriptions()
            ->where('status', 'active')
            ->count() * ($podcast->subscription_price ?? 0);

        return [
            'sponsorship' => $sponsorshipRevenue,
            'subscription' => $subscriptionRevenue,
            'estimated_total' => $sponsorshipRevenue + $subscriptionRevenue,
            'monthly_listens' => $monthlyListens,
        ];
    }
}
