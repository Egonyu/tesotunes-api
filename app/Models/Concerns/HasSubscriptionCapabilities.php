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

    public function getEffectiveSubscriptionPlan(): ?SubscriptionPlan
    {
        return $this->getActivePlan()
            ?? SubscriptionPlan::query()->where('slug', 'free')->where('is_active', true)->first();
    }

    public function getPlanLimit(string $key, mixed $default = null): mixed
    {
        $plan = $this->getActivePlan();

        if (! $plan) {
            return $default;
        }

        if (array_key_exists($key, $plan->entitlements ?? [])) {
            return $plan->entitlements[$key];
        }

        if (isset($plan->{$key}) && $plan->{$key} !== null) {
            return $plan->{$key};
        }

        return $plan->limits[$key] ?? $default;
    }

    public function getSubscriptionEntitlements(): array
    {
        return $this->getEffectiveSubscriptionPlan()?->entitlements ?? [];
    }

    public function getSubscriptionEntitlement(string $key, mixed $default = null): mixed
    {
        $entitlements = $this->getSubscriptionEntitlements();

        return array_key_exists($key, $entitlements) ? $entitlements[$key] : $default;
    }

    public function hasSubscriptionEntitlement(string $key): bool
    {
        return $this->getEffectiveSubscriptionPlan()?->allows($key) ?? false;
    }

    public function getSubscriptionLimit(string $key, ?int $default = 0): ?int
    {
        $value = $this->getSubscriptionEntitlement($key, $default);

        if ($value === null || (is_numeric($value) && (int) $value < 0)) {
            return null;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public function isWithinSubscriptionLimit(string $key, int $currentUsage): bool
    {
        $limit = $this->getSubscriptionLimit($key);

        return $limit === null || $currentUsage < $limit;
    }

    public function canDownload(): bool
    {
        $limit = $this->getSubscriptionEntitlement(
            'streaming.downloads_per_day',
            $this->getPlanLimit('max_downloads_per_day', 3)
        );

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
        return (int) $this->getSubscriptionEntitlement(
            'streaming.audio_quality_kbps',
            $this->getPlanLimit('max_audio_quality_kbps', 128)
        );
    }

    /**
     * Upload requires an artist plan, or an artist record with can_upload flag.
     */
    public function canUpload(): bool
    {
        $uploadLimit = $this->getSubscriptionEntitlement(
            'creator.uploads_per_month',
            $this->getPlanLimit('max_uploads_per_month', 0)
        );

        if ($uploadLimit !== 0) {
            return true;
        }

        return $this->artist && $this->artist->can_upload;
    }

    public function getMonthlyUploadLimit(): ?int
    {
        $planLimit = $this->getSubscriptionEntitlement(
            'creator.uploads_per_month',
            $this->getPlanLimit('max_uploads_per_month', null)
        );

        if ($planLimit !== null && $planLimit !== 0) {
            return $planLimit === -1 ? null : $planLimit;
        }

        return $this->artist?->monthly_upload_limit;
    }

    /**
     * One rule for "ad-free", used by ad serving and /user/subscription.
     *
     * Plans carry two flags for the same promise: the pricing page ticks
     * "Ad-Free" from has_ads, while this read only ad_free. A plan with
     * has_ads=false and ad_free=false was sold as ad-free and served ads.
     * Either flag now makes it ad-free, so nobody told "Ad-Free" sees an ad.
     */
    public function isAdFree(): bool
    {
        $plan = $this->getActivePlan();

        return $plan !== null && (bool) $this->getSubscriptionEntitlement(
            'streaming.ad_free',
            ((bool) $plan->ad_free || ! (bool) $plan->has_ads)
        );
    }

    public function canAccessOffline(): bool
    {
        $plan = $this->getActivePlan();

        return $plan && (bool) $this->getSubscriptionEntitlement(
            'streaming.offline',
            (bool) ($plan->allows_offline ?? false)
        );
    }

    public function getRemainingDownloadsAttribute(): int
    {
        $limit = $this->getSubscriptionEntitlement(
            'streaming.downloads_per_day',
            $this->getPlanLimit('max_downloads_per_day', 3)
        );

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
        $totalPlays = $this->playHistory()->where('completed', true)->count();
        $totalMinutes = $this->playHistory()
            ->where('completed', true)
            ->sum('duration_played_seconds') / 60;

        $topGenres = $this->playHistory()
            ->with('song.genres')
            ->where('completed', true)
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
