<?php

namespace App\Models\Concerns;

use App\Models\Download;
use App\Models\Song;
use App\Models\SongPurchase;
use App\Models\SubscriptionPlan;

trait HasSubscriptionCapabilities
{
    public function hasActiveSubscription(): bool
    {
        $sub = $this->subscription;

        return $sub
            && $sub->status === 'active'
            && $sub->expires_at
            && $sub->expires_at->isFuture();
    }

    public function getActivePlan(): ?SubscriptionPlan
    {
        if (! $this->hasActiveSubscription()) {
            return null;
        }

        return $this->subscription->subscriptionPlan;
    }

    public function getPlanLimit(string $key, mixed $default = null): mixed
    {
        $plan = $this->getActivePlan();

        if (! $plan) {
            return $default;
        }

        if (isset($plan->{$key}) && $plan->{$key} !== null) {
            return $plan->{$key};
        }

        return $plan->limits[$key] ?? $default;
    }

    public function canDownload(): bool
    {
        $limit = $this->getPlanLimit('max_downloads_per_day', 3);

        if ($limit === null || $limit === -1) {
            return true;
        }

        $todayDownloads = Download::where('user_id', $this->id)
            ->whereDate('downloaded_at', today())
            ->count();

        return $todayDownloads < $limit;
    }

    public function hasPurchasedSong(Song $song): bool
    {
        return SongPurchase::where('user_id', $this->id)
            ->where('song_id', $song->id)
            ->exists();
    }

    public function canPlayPremiumContent(): bool
    {
        return $this->hasActiveSubscription();
    }

    /**
     * All users can stream; quality is gated by plan via getMaxAudioQuality().
     */
    public function canStream(): bool
    {
        return true;
    }

    /**
     * Max audio quality (kbps) allowed by the user's plan. Free = 128, paid = 320.
     */
    public function getMaxAudioQuality(): int
    {
        return (int) $this->getPlanLimit('max_audio_quality_kbps', 128);
    }

    /**
     * Upload requires an artist plan, or an artist record with can_upload flag.
     */
    public function canUpload(): bool
    {
        $uploadLimit = $this->getPlanLimit('max_uploads_per_month', 0);

        if ($uploadLimit !== 0) {
            return true;
        }

        return $this->artist && $this->artist->can_upload;
    }

    public function getMonthlyUploadLimit(): ?int
    {
        $planLimit = $this->getPlanLimit('max_uploads_per_month', null);

        if ($planLimit !== null && $planLimit !== 0) {
            return $planLimit === -1 ? null : $planLimit;
        }

        return $this->artist?->monthly_upload_limit;
    }

    public function isAdFree(): bool
    {
        $plan = $this->getActivePlan();

        return $plan && (bool) ($plan->ad_free ?? false);
    }

    public function canAccessOffline(): bool
    {
        $plan = $this->getActivePlan();

        return $plan && (bool) ($plan->allows_offline ?? false);
    }

    public function getRemainingDownloadsAttribute(): int
    {
        $limit = $this->getPlanLimit('max_downloads_per_day', 3);

        if ($limit === null || $limit === -1) {
            return -1;
        }

        $todayDownloads = Download::where('user_id', $this->id)
            ->whereDate('downloaded_at', today())
            ->count();

        return max(0, $limit - $todayDownloads);
    }

    public function getOfflinePlaylistsAttribute()
    {
        return $this->playlists()
            ->where(function ($q) {
                $q->where('privacy', 'public')->orWhere('user_id', $this->id);
            })
            ->with(['songs' => function ($query) {
                $query->where('is_free', true)->where('status', 'published');
            }])
            ->get();
    }

    public function getListeningStatsAttribute(): array
    {
        $totalPlays = $this->playHistory()->where('was_completed', true)->count();
        $totalMinutes = $this->playHistory()
            ->where('was_completed', true)
            ->sum('duration_played_seconds') / 60;

        $topGenres = $this->playHistory()
            ->with('song.genres')
            ->where('was_completed', true)
            ->get()
            ->flatMap(fn ($history) => $history->song->genres)
            ->countBy('name')
            ->sortDesc()
            ->take(5);

        return [
            'total_plays' => $totalPlays,
            'total_minutes' => round($totalMinutes),
            'top_genres' => $topGenres,
        ];
    }
}
