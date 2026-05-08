<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Distribution eligibility checks and display helpers for songs.
 *
 * Why a trait: these methods all answer the question "what can this song do
 * in the distribution pipeline?"  They were added to Song.php one by one as
 * distribution was built, but none belong in Song's core identity.
 */
trait HasDistributionStatus
{
    /**
     * Whether this song can be downloaded by the given user.
     *
     * - Published + is_downloadable required.
     * - Free songs: always downloadable when above conditions met.
     * - Paid songs: require purchase or active premium subscription.
     */
    public function isAvailableForDownload(?User $user = null): bool
    {
        if ($this->status !== 'published' || ! $this->is_downloadable) {
            return false;
        }

        if ($this->is_free) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->hasPurchasedSong($this) || $user->hasActiveSubscription();
    }

    public function isReadyForDistribution(): bool
    {
        return $this->distribution_status === 'approved'
            && $this->isrc_code
            && $this->audio_quality_score >= 7
            && $this->file_size_bytes > 0;
    }

    public function canBeDistributed(): bool
    {
        return $this->status === 'published'
            && $this->distribution_status === 'approved'
            && $this->isrc_code !== null;
    }

    /**
     * Estimate net revenue share for a given platform and stream count.
     * Rates are approximate and subject to platform agreement changes.
     */
    public function estimateDistributionRevenue(string $platform, int $streams): float
    {
        $platformRates = [
            'spotify' => 0.003,
            'apple_music' => 0.007,
            'youtube_music' => 0.001,
            'boomplay' => 0.002,
            'deezer' => 0.006,
        ];

        $rate = $platformRates[strtolower($platform)] ?? 0.002;
        $grossRevenue = $streams * $rate;
        $netRevenue = $grossRevenue * 0.7; // typical 30% platform cut

        return $netRevenue * ($this->master_ownership_percentage / 100);
    }

    public function getFormattedFileSizeAttribute(): string
    {
        if (! $this->file_size_bytes) {
            return 'Unknown';
        }

        $bytes = $this->file_size_bytes;

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    public function getAudioQualityBadgeAttribute(): string
    {
        return match (true) {
            $this->audio_quality_score >= 95 => '🔊 Studio Quality',
            $this->audio_quality_score >= 85 => '🎵 High Quality',
            $this->audio_quality_score >= 70 => '🎶 Standard',
            $this->audio_quality_score > 0 => '📱 Mobile Optimized',
            default => '❓ Unknown',
        };
    }

    public function getDistributionStatusBadgeAttribute(): string
    {
        return match ($this->distribution_status) {
            'draft' => '📝 Draft',
            'pending_review' => '⏳ Pending Review',
            'approved' => '✅ Approved',
            'rejected' => '❌ Rejected',
            'distributed' => '🌍 Live',
            default => '❓ Unknown',
        };
    }
}
