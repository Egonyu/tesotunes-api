<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Models\Artist;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Artist Settings Service
 * 
 * Handles all business logic related to artist management settings.
 * This service centralizes artist configuration management and provides
 * reusable methods for artist-related business rules.
 */
class ArtistSettingsService
{
    /**
     * Get all artist-related settings.
     * 
     * @return array
     */
    public function getSettings(): array
    {
        return Cache::remember('settings.artists', 3600, function () {
            return [
                // Verification settings
                'artist_verification_required' => Setting::get('artist_verification_required', true),
                'artist_auto_approval' => Setting::get('artist_auto_approval', false),
                'artist_max_uploads' => Setting::get('artist_max_uploads', 20),
                'verification_review_period' => Setting::get('verification_review_period', 3),
                'require_government_id' => Setting::get('require_government_id', true),
                
                // Monetization settings
                'monetization_enabled' => Setting::get('artist_monetization_enabled', true),
                'artist_revenue_share' => Setting::get('artist_revenue_share', 70),
                'min_payout' => Setting::get('artist_min_payout', 50),
                'auto_payout' => Setting::get('artist_auto_payout', false),
                'payout_frequency' => Setting::get('artist_payout_frequency', 'monthly'),
                'require_tax_info' => Setting::get('artist_require_tax_info', false),
                
                // Restrictions settings
                'max_pending_uploads' => Setting::get('artist_max_pending_uploads', 10),
                'upload_cooldown_hours' => Setting::get('artist_upload_cooldown_hours', 0),
                'require_admin_review' => Setting::get('artist_require_admin_review', true),
                'auto_publish_after_review' => Setting::get('artist_auto_publish_after_review', true),
                'max_collaborators_per_song' => Setting::get('artist_max_collaborators_per_song', 5),
                
                // Profile settings
                'require_artist_bio' => Setting::get('artist_require_bio', false),
                'min_bio_length' => Setting::get('artist_min_bio_length', 50),
                'require_profile_photo' => Setting::get('artist_require_profile_photo', true),
                'require_banner_image' => Setting::get('artist_require_banner_image', false),
            ];
        });
    }

    /**
     * Update artist verification settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateVerificationSettings(array $data): bool
    {
        try {
            DB::beginTransaction();

            $settings = [
                'artist_verification_required' => $data['artist_verification_required'] ?? true,
                'artist_auto_approval' => $data['artist_auto_approval'] ?? false,
                'artist_max_uploads' => (int) ($data['artist_max_uploads'] ?? 20),
                'verification_review_period' => (int) ($data['verification_review_period'] ?? 3),
                'require_government_id' => $data['require_government_id'] ?? true,
            ];

            // Validate max uploads
            if ($settings['artist_max_uploads'] < 1 || $settings['artist_max_uploads'] > 1000) {
                throw new \Exception('Max uploads must be between 1 and 1000');
            }

            // Validate review period
            if ($settings['verification_review_period'] < 1 || $settings['verification_review_period'] > 30) {
                throw new \Exception('Verification review period must be between 1 and 30 days');
            }

            // Save settings
            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_ARTISTS);
            }

            DB::commit();
            $this->clearCache();

            Log::info('Artist verification settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update artist verification settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Update artist monetization settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateMonetizationSettings(array $data): bool
    {
        try {
            DB::beginTransaction();

            $settings = [
                'artist_monetization_enabled' => $data['monetization_enabled'] ?? true,
                'artist_revenue_share' => (float) ($data['artist_revenue_share'] ?? 70),
                'artist_min_payout' => (float) ($data['min_payout'] ?? 50),
                'artist_auto_payout' => $data['auto_payout'] ?? false,
                'artist_payout_frequency' => $data['payout_frequency'] ?? 'monthly',
                'artist_require_tax_info' => $data['require_tax_info'] ?? false,
            ];

            // Validate revenue share (must be between 0 and 100)
            if ($settings['artist_revenue_share'] < 0 || $settings['artist_revenue_share'] > 100) {
                throw new \Exception('Revenue share must be between 0% and 100%');
            }

            // Validate minimum payout
            if ($settings['artist_min_payout'] < 0) {
                throw new \Exception('Minimum payout cannot be negative');
            }

            // Validate payout frequency
            $validFrequencies = ['weekly', 'bi-weekly', 'monthly', 'quarterly'];
            if (!in_array($settings['artist_payout_frequency'], $validFrequencies)) {
                throw new \Exception('Invalid payout frequency');
            }

            // Save settings
            foreach ($settings as $key => $value) {
                $type = is_bool($value) 
                    ? Setting::TYPE_BOOLEAN 
                    : (is_numeric($value) ? Setting::TYPE_NUMBER : Setting::TYPE_STRING);
                Setting::set($key, $value, $type, Setting::GROUP_ARTISTS);
            }

            DB::commit();
            $this->clearCache();

            Log::info('Artist monetization settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings),
                'revenue_share' => $settings['artist_revenue_share']
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update artist monetization settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Update artist restrictions settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateRestrictionsSettings(array $data): bool
    {
        try {
            DB::beginTransaction();

            $settings = [
                'artist_max_pending_uploads' => (int) ($data['max_pending_uploads'] ?? 10),
                'artist_upload_cooldown_hours' => (int) ($data['upload_cooldown_hours'] ?? 0),
                'artist_require_admin_review' => $data['require_admin_review'] ?? true,
                'artist_auto_publish_after_review' => $data['auto_publish_after_review'] ?? true,
                'artist_max_collaborators_per_song' => (int) ($data['max_collaborators_per_song'] ?? 5),
            ];

            // Validate max pending uploads
            if ($settings['artist_max_pending_uploads'] < 1 || $settings['artist_max_pending_uploads'] > 100) {
                throw new \Exception('Max pending uploads must be between 1 and 100');
            }

            // Validate upload cooldown
            if ($settings['artist_upload_cooldown_hours'] < 0 || $settings['artist_upload_cooldown_hours'] > 168) {
                throw new \Exception('Upload cooldown must be between 0 and 168 hours (1 week)');
            }

            // Validate max collaborators
            if ($settings['artist_max_collaborators_per_song'] < 1 || $settings['artist_max_collaborators_per_song'] > 20) {
                throw new \Exception('Max collaborators per song must be between 1 and 20');
            }

            // Save settings
            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_ARTISTS);
            }

            DB::commit();
            $this->clearCache();

            Log::info('Artist restrictions settings updated', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update artist restrictions settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // ==================== Business Logic Methods ====================

    /**
     * Check if artist verification is required.
     * 
     * @return bool
     */
    public function isVerificationRequired(): bool
    {
        return Setting::get('artist_verification_required', true);
    }

    /**
     * Check if artist auto-approval is enabled.
     * 
     * @return bool
     */
    public function isAutoApprovalEnabled(): bool
    {
        return Setting::get('artist_auto_approval', false);
    }

    /**
     * Get maximum uploads allowed per month for an artist.
     * 
     * @return int
     */
    public function getMaxUploadsPerMonth(): int
    {
        return Setting::get('artist_max_uploads', 20);
    }

    /**
     * Get verification review period in days.
     * 
     * @return int
     */
    public function getVerificationReviewPeriod(): int
    {
        return Setting::get('verification_review_period', 3);
    }

    /**
     * Check if monetization is enabled for artists.
     * 
     * @return bool
     */
    public function isMonetizationEnabled(): bool
    {
        return Setting::get('artist_monetization_enabled', true);
    }

    /**
     * Get artist revenue share percentage.
     * 
     * @return float
     */
    public function getRevenueShare(): float
    {
        return Setting::get('artist_revenue_share', 70);
    }

    /**
     * Get minimum payout amount.
     * 
     * @return float
     */
    public function getMinimumPayout(): float
    {
        return Setting::get('artist_min_payout', 50);
    }

    /**
     * Check if auto-payout is enabled.
     * 
     * @return bool
     */
    public function isAutoPayoutEnabled(): bool
    {
        return Setting::get('artist_auto_payout', false);
    }

    /**
     * Get payout frequency.
     * 
     * @return string
     */
    public function getPayoutFrequency(): string
    {
        return Setting::get('artist_payout_frequency', 'monthly');
    }

    /**
     * Get maximum pending uploads allowed.
     * 
     * @return int
     */
    public function getMaxPendingUploads(): int
    {
        return Setting::get('artist_max_pending_uploads', 10);
    }

    /**
     * Get upload cooldown period in hours.
     * 
     * @return int
     */
    public function getUploadCooldownHours(): int
    {
        return Setting::get('artist_upload_cooldown_hours', 0);
    }

    /**
     * Check if admin review is required for uploads.
     * 
     * @return bool
     */
    public function requiresAdminReview(): bool
    {
        return Setting::get('artist_require_admin_review', true);
    }

    /**
     * Get maximum collaborators allowed per song.
     * 
     * @return int
     */
    public function getMaxCollaboratorsPerSong(): int
    {
        return Setting::get('artist_max_collaborators_per_song', 5);
    }

    /**
     * Check if artist can upload more songs.
     * 
     * @param int $artistId
     * @return array ['can_upload' => bool, 'reason' => string|null, 'remaining' => int]
     */
    public function canArtistUpload(int $artistId): array
    {
        $artist = Artist::find($artistId);
        if (!$artist) {
            return ['can_upload' => false, 'reason' => 'Artist not found', 'remaining' => 0];
        }

        // Check if artist is verified (if verification is required)
        if ($this->isVerificationRequired() && !$artist->is_verified) {
            return ['can_upload' => false, 'reason' => 'Artist verification required', 'remaining' => 0];
        }

        // Check monthly upload limit
        $maxUploads = $this->getMaxUploadsPerMonth();
        $uploadsThisMonth = $artist->songs()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($uploadsThisMonth >= $maxUploads) {
            return [
                'can_upload' => false,
                'reason' => 'Monthly upload limit reached',
                'remaining' => 0
            ];
        }

        // Check pending uploads limit
        $maxPending = $this->getMaxPendingUploads();
        $pendingUploads = $artist->songs()
            ->where('status', 'pending')
            ->count();

        if ($pendingUploads >= $maxPending) {
            return [
                'can_upload' => false,
                'reason' => 'Too many pending uploads. Please wait for approval.',
                'remaining' => 0
            ];
        }

        // Check upload cooldown
        $cooldownHours = $this->getUploadCooldownHours();
        if ($cooldownHours > 0) {
            $lastUpload = $artist->songs()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastUpload && $lastUpload->created_at->diffInHours(now()) < $cooldownHours) {
                $hoursRemaining = $cooldownHours - $lastUpload->created_at->diffInHours(now());
                return [
                    'can_upload' => false,
                    'reason' => "Upload cooldown active. Try again in {$hoursRemaining} hours.",
                    'remaining' => 0
                ];
            }
        }

        $remaining = $maxUploads - $uploadsThisMonth;
        return [
            'can_upload' => true,
            'reason' => null,
            'remaining' => $remaining
        ];
    }

    /**
     * Calculate artist revenue from a given amount.
     * 
     * @param float $totalRevenue
     * @return float
     */
    public function calculateArtistRevenue(float $totalRevenue): float
    {
        $revenueShare = $this->getRevenueShare();
        return $totalRevenue * ($revenueShare / 100);
    }

    /**
     * Check if artist is eligible for payout.
     * 
     * @param int $artistId
     * @return array ['eligible' => bool, 'reason' => string|null, 'amount' => float]
     */
    public function isEligibleForPayout(int $artistId): array
    {
        if (!$this->isMonetizationEnabled()) {
            return ['eligible' => false, 'reason' => 'Monetization is disabled', 'amount' => 0];
        }

        $artist = Artist::find($artistId);
        if (!$artist) {
            return ['eligible' => false, 'reason' => 'Artist not found', 'amount' => 0];
        }

        // Get pending earnings
        $pendingEarnings = $artist->getPendingEarnings();

        $minPayout = $this->getMinimumPayout();
        if ($pendingEarnings < $minPayout) {
            return [
                'eligible' => false,
                'reason' => "Minimum payout amount not reached. Current: $pendingEarnings, Required: $minPayout",
                'amount' => $pendingEarnings
            ];
        }

        // Check if payment information is set
        if (!$artist->hasPaymentInfo()) {
            return [
                'eligible' => false,
                'reason' => 'Payment information not configured',
                'amount' => $pendingEarnings
            ];
        }

        // Check if tax info is required and set
        if (Setting::get('artist_require_tax_info', false) && !$artist->hasTaxInfo()) {
            return [
                'eligible' => false,
                'reason' => 'Tax information required',
                'amount' => $pendingEarnings
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'amount' => $pendingEarnings
        ];
    }

    /**
     * Get artist verification statistics.
     * 
     * @return array
     */
    public function getVerificationStatistics(): array
    {
        return [
            'total_artists' => Artist::count(),
            'verified_artists' => Artist::where('is_verified', true)->count(),
            'pending_verification' => Artist::where('is_verified', false)
                ->whereNotNull('verification_requested_at')
                ->count(),
            'unverified_artists' => Artist::where('is_verified', false)
                ->whereNull('verification_requested_at')
                ->count(),
            'verification_rate' => Artist::count() > 0 
                ? round((Artist::where('is_verified', true)->count() / Artist::count()) * 100, 2) 
                : 0,
        ];
    }

    /**
     * Get artist monetization statistics.
     * 
     * @return array
     */
    public function getMonetizationStatistics(): array
    {
        $artists = Artist::with('songs')->get();
        
        $totalEarnings = $artists->sum(function ($artist) {
            return $artist->songs->sum('revenue_generated');
        });

        $artistShare = $this->calculateArtistRevenue($totalEarnings);
        $platformShare = $totalEarnings - $artistShare;

        return [
            'total_revenue' => $totalEarnings,
            'artist_share' => $artistShare,
            'platform_share' => $platformShare,
            'artists_earning' => $artists->filter(function ($artist) {
                return $artist->songs->sum('revenue_generated') > 0;
            })->count(),
            'average_artist_earnings' => $artists->count() > 0 
                ? round($artistShare / $artists->count(), 2) 
                : 0,
        ];
    }

    /**
     * Clear settings cache.
     */
    private function clearCache(): void
    {
        Cache::forget('settings.artists');
        Cache::tags(['settings'])->flush();
    }
}
