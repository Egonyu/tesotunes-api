<?php

namespace App\Services\Podcast;

use App\Models\Podcast;
use App\Models\PodcastEpisode;
use App\Models\PodcastListen;
use App\Models\PodcastDownload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsService
{
    /**
     * Track a listen event.
     */
    public function trackListen(PodcastEpisode $episode, array $data): PodcastListen
    {
        $listen = PodcastListen::create([
            'episode_id' => $episode->id,
            'podcast_id' => $episode->podcast_id,
            'user_id' => $data['user_id'] ?? null,
            'session_id' => $data['session_id'],
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'listen_duration' => $data['listen_duration'],
            'episode_duration' => $episode->duration,
            'device_type' => $data['device_type'] ?? 'unknown',
            'platform' => $data['platform'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'last_position' => $data['last_position'] ?? 0,
            'listened_at' => now(),
        ]);

        // Update episode listen count
        $episode->incrementListenCount();

        // Update completion rate if listen is significant (>10%)
        $completionPercentage = ($data['listen_duration'] / $episode->duration) * 100;
        if ($completionPercentage > 10) {
            $episode->updateCompletionRate();
        }

        return $listen;
    }

    /**
     * Track a download event.
     */
    public function trackDownload(PodcastEpisode $episode, array $data): PodcastDownload
    {
        $download = PodcastDownload::create([
            'episode_id' => $episode->id,
            'podcast_id' => $episode->podcast_id,
            'user_id' => $data['user_id'] ?? null,
            'quality' => $data['quality'] ?? 'medium',
            'file_size' => $data['file_size'] ?? $episode->file_size,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'downloaded_at' => now(),
        ]);

        // Update episode download count
        $episode->incrementDownloadCount();

        return $download;
    }

    /**
     * Get podcast analytics for a date range.
     */
    public function getPodcastAnalytics(Podcast $podcast, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30);
        $endDate = $endDate ?? now();

        return [
            'total_listens' => $this->getTotalListens($podcast, $startDate, $endDate),
            'total_downloads' => $this->getTotalDownloads($podcast, $startDate, $endDate),
            'unique_listeners' => $this->getUniqueListeners($podcast, $startDate, $endDate),
            'average_completion_rate' => $this->getAverageCompletionRate($podcast, $startDate, $endDate),
            'listens_by_day' => $this->getListensByDay($podcast, $startDate, $endDate),
            'listens_by_device' => $this->getListensByDevice($podcast, $startDate, $endDate),
            'listens_by_country' => $this->getListensByCountry($podcast, $startDate, $endDate),
            'top_episodes' => $this->getTopEpisodes($podcast, $startDate, $endDate),
        ];
    }

    /**
     * Get total listens for a podcast.
     */
    protected function getTotalListens(Podcast $podcast, Carbon $startDate, Carbon $endDate): int
    {
        return PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->count();
    }

    /**
     * Get total downloads for a podcast.
     */
    protected function getTotalDownloads(Podcast $podcast, Carbon $startDate, Carbon $endDate): int
    {
        return PodcastDownload::where('podcast_id', $podcast->id)
            ->whereBetween('downloaded_at', [$startDate, $endDate])
            ->count();
    }

    /**
     * Get unique listeners count.
     */
    protected function getUniqueListeners(Podcast $podcast, Carbon $startDate, Carbon $endDate): int
    {
        return PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * Get average completion rate.
     */
    protected function getAverageCompletionRate(Podcast $podcast, Carbon $startDate, Carbon $endDate): float
    {
        $avgCompletion = PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->selectRaw('AVG((listen_duration / episode_duration) * 100) as avg_completion')
            ->value('avg_completion');

        return round($avgCompletion ?? 0, 2);
    }

    /**
     * Get listens grouped by day.
     */
    protected function getListensByDay(Podcast $podcast, Carbon $startDate, Carbon $endDate): array
    {
        $listens = PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->selectRaw('DATE(listened_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $listens->pluck('count', 'date')->toArray();
    }

    /**
     * Get listens grouped by device type.
     */
    protected function getListensByDevice(Podcast $podcast, Carbon $startDate, Carbon $endDate): array
    {
        $listens = PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->selectRaw('device_type, COUNT(*) as count')
            ->groupBy('device_type')
            ->get();

        return $listens->pluck('count', 'device_type')->toArray();
    }

    /**
     * Get listens grouped by country.
     */
    protected function getListensByCountry(Podcast $podcast, Carbon $startDate, Carbon $endDate): array
    {
        $listens = PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->whereNotNull('country')
            ->selectRaw('country, COUNT(*) as count')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return $listens->pluck('count', 'country')->toArray();
    }

    /**
     * Get top episodes by listen count.
     */
    protected function getTopEpisodes(Podcast $podcast, Carbon $startDate, Carbon $endDate, int $limit = 10): array
    {
        $episodes = PodcastListen::where('podcast_id', $podcast->id)
            ->whereBetween('listened_at', [$startDate, $endDate])
            ->select('episode_id', DB::raw('COUNT(*) as listen_count'))
            ->groupBy('episode_id')
            ->orderByDesc('listen_count')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($episodes as $item) {
            $episode = PodcastEpisode::find($item->episode_id);
            if ($episode) {
                $result[] = [
                    'episode_id' => $episode->id,
                    'title' => $episode->title,
                    'slug' => $episode->slug,
                    'listen_count' => $item->listen_count,
                ];
            }
        }

        return $result;
    }

    /**
     * Check if user has exceeded free episode limit.
     */
    public function hasExceededFreeLimit(User $user): bool
    {
        if ($user->subscription_tier === 'premium') {
            return false;
        }

        $limit = config('podcast.freemium.free_episode_limit_per_month', 5);
        
        $listenedCount = PodcastListen::where('user_id', $user->id)
            ->whereMonth('listened_at', now()->month)
            ->whereYear('listened_at', now()->year)
            ->distinct('episode_id')
            ->count('episode_id');

        return $listenedCount >= $limit;
    }

    /**
     * Get user's listen history.
     */
    public function getUserListeningHistory(User $user, int $limit = 20): array
    {
        $listens = PodcastListen::where('user_id', $user->id)
            ->with('episode.podcast')
            ->orderByDesc('listened_at')
            ->limit($limit)
            ->get();

        return $listens->map(function ($listen) {
            return [
                'episode' => [
                    'id' => $listen->episode->uuid,
                    'title' => $listen->episode->title,
                    'podcast_title' => $listen->episode->podcast->title,
                ],
                'listened_at' => $listen->listened_at->toIso8601String(),
                'completion_percentage' => $listen->completion_percentage,
                'last_position' => $listen->last_position,
            ];
        })->toArray();
    }
}
