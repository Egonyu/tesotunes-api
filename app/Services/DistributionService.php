<?php

namespace App\Services;

use App\Models\Song;
use App\Models\Distribution;
use App\Models\DistributionPlatform;
use App\Models\User;
use App\Models\Artist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Collection;
use Exception;

/**
 * Service class for handling music distribution to external platforms
 *
 * This service manages:
 * - Distribution to streaming platforms (Spotify, Apple Music, etc.)
 * - Rights management and metadata validation
 * - Revenue tracking and royalty distribution
 * - Platform sync and status monitoring
 * - Content delivery and quality control
 */
class DistributionService
{
    protected MusicStorageService $storageService;

    // Supported distribution platforms
    const PLATFORMS = [
        'spotify' => 'Spotify',
        'apple_music' => 'Apple Music',
        'youtube_music' => 'YouTube Music',
        'amazon_music' => 'Amazon Music',
        'deezer' => 'Deezer',
        'tidal' => 'Tidal',
        'pandora' => 'Pandora',
        'soundcloud' => 'SoundCloud',
        'bandcamp' => 'Bandcamp',
    ];

    // Distribution statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_LIVE = 'live';
    const STATUS_FAILED = 'failed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_REMOVED = 'removed';

    public function __construct(MusicStorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    /**
     * Submit song for distribution to selected platforms
     */
    public function distributeMusic(Song $song, array $platforms, array $distributionData = []): array
    {
        // Validate song eligibility for distribution
        $this->validateSongForDistribution($song);

        // Validate artist rights and permissions
        $this->validateArtistRights($song->artist);

        DB::beginTransaction();

        try {
            $distributions = [];

            foreach ($platforms as $platformCode) {
                if (!array_key_exists($platformCode, self::PLATFORMS)) {
                    throw new Exception("Unsupported platform: {$platformCode}");
                }

                $distribution = $this->createDistribution($song, $platformCode, $distributionData);
                $distributions[] = $distribution;

                // Queue distribution job for each platform
                $this->queueDistributionJob($distribution);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Music submitted for distribution',
                'distributions' => $distributions,
                'estimated_delivery' => now()->addDays(3)->format('Y-m-d'),
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update distribution status and metadata
     */
    public function updateDistributionStatus(
        Distribution $distribution,
        string $status,
        array $metadata = []
    ): Distribution {
        $validStatuses = [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_LIVE,
            self::STATUS_FAILED,
            self::STATUS_REJECTED,
            self::STATUS_REMOVED
        ];

        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid distribution status: {$status}");
        }

        $updateData = [
            'status' => $status,
            'last_updated' => now(),
        ];

        // Handle status-specific updates
        switch ($status) {
            case self::STATUS_LIVE:
                $updateData['live_date'] = now();
                $updateData['platform_url'] = $metadata['platform_url'] ?? null;
                $updateData['platform_id'] = $metadata['platform_id'] ?? null;
                break;

            case self::STATUS_FAILED:
            case self::STATUS_REJECTED:
                $updateData['error_message'] = $metadata['error_message'] ?? 'Unknown error';
                $updateData['rejection_reason'] = $metadata['rejection_reason'] ?? null;
                break;

            case self::STATUS_REMOVED:
                $updateData['removed_date'] = now();
                $updateData['removal_reason'] = $metadata['removal_reason'] ?? null;
                break;
        }

        if (!empty($metadata)) {
            $updateData['platform_metadata'] = array_merge(
                $distribution->platform_metadata ?? [],
                $metadata
            );
        }

        $distribution->update($updateData);

        // Notify artist of significant status changes
        if (in_array($status, [self::STATUS_LIVE, self::STATUS_FAILED, self::STATUS_REJECTED])) {
            $this->notifyArtistOfStatusChange($distribution, $status);
        }

        return $distribution->fresh();
    }

    /**
     * Remove music from distribution platforms
     */
    public function removeFromDistribution(Song $song, array $platforms = []): array
    {
        $query = Distribution::where('song_id', $song->id)
            ->whereIn('status', [self::STATUS_LIVE, self::STATUS_PROCESSING]);

        if (!empty($platforms)) {
            $query->whereIn('platform_code', $platforms);
        }

        $distributions = $query->get();

        $results = [];

        foreach ($distributions as $distribution) {
            try {
                // Queue removal job
                $this->queueRemovalJob($distribution);

                $distribution->update([
                    'status' => self::STATUS_PENDING,
                    'removal_requested_at' => now(),
                ]);

                $results[] = [
                    'platform' => $distribution->platform_code,
                    'status' => 'removal_queued',
                    'message' => 'Removal request submitted'
                ];

            } catch (Exception $e) {
                $results[] = [
                    'platform' => $distribution->platform_code,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Get distribution analytics and revenue data
     */
    public function getDistributionAnalytics(Song $song, int $days = 30): array
    {
        $distributions = Distribution::where('song_id', $song->id)
            ->get();

        $analytics = [
            'total_platforms' => $distributions->count(),
            'live_platforms' => $distributions->where('status', self::STATUS_LIVE)->count(),
            'pending_platforms' => $distributions->where('status', self::STATUS_PENDING)->count(),
            'failed_platforms' => $distributions->where('status', self::STATUS_FAILED)->count(),
            'total_streams' => 0,
            'total_revenue' => 0,
            'platform_breakdown' => [],
            'revenue_trends' => [],
        ];

        foreach ($distributions as $distribution) {
            $platformData = $this->getPlatformAnalytics($distribution, $days);

            $analytics['total_streams'] += $platformData['streams'];
            $analytics['total_revenue'] += $platformData['revenue'];

            $analytics['platform_breakdown'][$distribution->platform_code] = [
                'status' => $distribution->status,
                'streams' => $platformData['streams'],
                'revenue' => $platformData['revenue'],
                'live_date' => $distribution->live_date,
                'platform_url' => $distribution->platform_url,
            ];
        }

        return $analytics;
    }

    /**
     * Sync distribution data from external platforms
     */
    public function syncDistributionData(Distribution $distribution): array
    {
        try {
            $platformService = $this->getPlatformService($distribution->platform_code);
            $syncData = $platformService->syncData($distribution);

            // Update local data with platform data
            $distribution->update([
                'platform_metadata' => array_merge(
                    $distribution->platform_metadata ?? [],
                    $syncData['metadata'] ?? []
                ),
                'last_synced' => now(),
            ]);

            // Update revenue data if available
            if (isset($syncData['revenue_data'])) {
                $this->updateRevenueData($distribution, $syncData['revenue_data']);
            }

            return [
                'success' => true,
                'message' => 'Distribution data synced successfully',
                'data' => $syncData
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to sync distribution data',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get all active distributions for an artist
     */
    public function getArtistDistributions(Artist $artist): Collection
    {
        return Distribution::where('artist_id', $artist->id)
            ->with(['song'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Calculate revenue distribution and royalties
     */
    public function calculateRoyalties(Distribution $distribution, float $grossRevenue): array
    {
        $platformRate = $this->getPlatformRoyaltyRate($distribution->platform_code);
        $serviceRate = config('distribution.service_fee_percentage', 10);

        $platformFee = $grossRevenue * ($platformRate / 100);
        $serviceFee = ($grossRevenue - $platformFee) * ($serviceRate / 100);
        $artistEarnings = $grossRevenue - $platformFee - $serviceFee;

        return [
            'gross_revenue' => $grossRevenue,
            'platform_fee' => $platformFee,
            'service_fee' => $serviceFee,
            'artist_earnings' => $artistEarnings,
            'platform_rate' => $platformRate,
            'service_rate' => $serviceRate,
        ];
    }

    /**
     * Generate distribution report for artist
     */
    public function generateDistributionReport(Artist $artist, string $startDate, string $endDate): array
    {
        $distributions = $this->getArtistDistributions($artist)
            ->filter(function($distribution) use ($startDate, $endDate) {
                return $distribution->created_at >= $startDate &&
                       $distribution->created_at <= $endDate;
            });

        $report = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'artist' => $artist->only(['id', 'name', 'stage_name']),
            'summary' => [
                'total_songs_distributed' => $distributions->pluck('song_id')->unique()->count(),
                'total_platforms' => $distributions->pluck('platform_code')->unique()->count(),
                'active_distributions' => $distributions->where('status', self::STATUS_LIVE)->count(),
                'total_revenue' => 0,
                'total_streams' => 0,
            ],
            'platform_breakdown' => [],
            'song_performance' => [],
        ];

        // Calculate totals and breakdowns
        foreach ($distributions as $distribution) {
            $analytics = $this->getPlatformAnalytics($distribution,
                now()->diffInDays($startDate));

            $report['summary']['total_revenue'] += $analytics['revenue'];
            $report['summary']['total_streams'] += $analytics['streams'];

            // Platform breakdown
            if (!isset($report['platform_breakdown'][$distribution->platform_code])) {
                $report['platform_breakdown'][$distribution->platform_code] = [
                    'platform_name' => self::PLATFORMS[$distribution->platform_code],
                    'songs_count' => 0,
                    'revenue' => 0,
                    'streams' => 0,
                ];
            }

            $report['platform_breakdown'][$distribution->platform_code]['songs_count']++;
            $report['platform_breakdown'][$distribution->platform_code]['revenue'] += $analytics['revenue'];
            $report['platform_breakdown'][$distribution->platform_code]['streams'] += $analytics['streams'];
        }

        return $report;
    }

    /**
     * Validate song meets distribution requirements
     */
    protected function validateSongForDistribution(Song $song): void
    {
        if ($song->status !== 'published') {
            throw new Exception('Song must be published before distribution');
        }

        if ($song->visibility === 'private') {
            throw new Exception('Song must be public for distribution');
        }

        if (empty($song->title) || empty($song->artist_id)) {
            throw new Exception('Song must have title and artist');
        }

        // Check file quality requirements
        $fileSize = $song->file_size_bytes ?? $song->file_size ?? 0;
        if ($fileSize < 1000000) { // 1MB minimum
            throw new Exception('Audio file too small for distribution');
        }

        // Check duration requirements
        $duration = $song->duration_seconds ?? $song->duration ?? 0;
        if ($duration < 30) { // 30 seconds minimum
            throw new Exception('Song too short for distribution (minimum 30 seconds)');
        }

        if ($duration > 900) { // 15 minutes maximum
            throw new Exception('Song too long for distribution (maximum 15 minutes)');
        }
    }

    /**
     * Validate artist has rights to distribute the music
     */
    protected function validateArtistRights(Artist $artist): void
    {
        if ($artist->distribution_suspended) {
            throw new Exception('Artist distribution privileges are suspended');
        }

        if (!$artist->hasDistributionRights()) {
            throw new Exception('Artist does not have distribution rights');
        }

        if (!$artist->hasCompletedProfile()) {
            throw new Exception('Artist profile must be completed before distribution');
        }
    }

    /**
     * Create distribution record
     */
    protected function createDistribution(Song $song, string $platformCode, array $data): Distribution
    {
        return Distribution::create([
            'song_id' => $song->id,
            'artist_id' => $song->artist_id,
            'platform_code' => $platformCode,
            'platform_name' => self::PLATFORMS[$platformCode],
            'status' => self::STATUS_PENDING,
            'distribution_metadata' => [
                'release_date' => $data['release_date'] ?? now()->addDays(7)->format('Y-m-d'),
                'territories' => $data['territories'] ?? ['worldwide'],
                'content_advisory' => $song->is_explicit ? 'explicit' : 'clean',
                'genre' => $song->primaryGenre?->name ?? ($song->genres->first()?->name),
                'language' => $song->primary_language,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Queue distribution job for processing
     */
    protected function queueDistributionJob(Distribution $distribution): void
    {
        // Queue background job to handle actual distribution
        dispatch(new \App\Jobs\ProcessDistribution($distribution));
    }

    /**
     * Queue removal job for processing
     */
    protected function queueRemovalJob(Distribution $distribution): void
    {
        // Queue background job to handle distribution removal
        dispatch(new \App\Jobs\RemoveFromDistribution($distribution));
    }

    /**
     * Get platform-specific service handler
     */
    protected function getPlatformService(string $platformCode)
    {
        $serviceClass = "\\App\\Services\\Distribution\\{$platformCode}Service";

        if (!class_exists($serviceClass)) {
            throw new Exception("Platform service not found: {$platformCode}");
        }

        return app($serviceClass);
    }

    /**
     * Get platform analytics data
     */
    protected function getPlatformAnalytics(Distribution $distribution, int $days): array
    {
        // Use total_streams and total_revenue from distribution table
        // Fall back to platform_metadata if not set
        return [
            'streams' => $distribution->total_streams ?? ($distribution->platform_metadata['streams'] ?? 0),
            'revenue' => $distribution->total_revenue ?? ($distribution->platform_metadata['revenue'] ?? 0),
            'last_updated' => $distribution->last_synced,
        ];
    }

    /**
     * Update revenue data for distribution
     */
    protected function updateRevenueData(Distribution $distribution, array $revenueData): void
    {
        // Update or create revenue records
        DB::table('distribution_revenue')->updateOrInsert([
            'distribution_id' => $distribution->id,
            'reporting_period' => $revenueData['period'],
        ], [
            'streams' => $revenueData['streams'],
            'revenue' => $revenueData['revenue'],
            'currency' => $revenueData['currency'] ?? 'USD',
            'updated_at' => now(),
        ]);
    }

    /**
     * Get platform royalty rate
     */
    protected function getPlatformRoyaltyRate(string $platformCode): float
    {
        $rates = [
            'spotify' => 70.0,
            'apple_music' => 71.0,
            'youtube_music' => 55.0,
            'amazon_music' => 69.0,
            'deezer' => 65.0,
            'tidal' => 75.0,
            'pandora' => 60.0,
            'soundcloud' => 55.0,
            'bandcamp' => 85.0,
        ];

        return $rates[$platformCode] ?? 65.0; // Default rate
    }

    /**
     * Notify artist of distribution status change
     */
    protected function notifyArtistOfStatusChange(Distribution $distribution, string $status): void
    {
        $messages = [
            self::STATUS_LIVE => "Your song '{$distribution->song->title}' is now live on {$distribution->platform_name}!",
            self::STATUS_FAILED => "Distribution to {$distribution->platform_name} failed. Please check the details.",
            self::STATUS_REJECTED => "Your song was rejected by {$distribution->platform_name}. Please review the feedback.",
        ];

        $artist = $distribution->song->artist;
        
        $artist->user->notifications()->create([
            'user_id' => $artist->user_id,
            'notification_type' => 'distribution_status_change',
            'title' => 'Distribution Update',
            'message' => $messages[$status] ?? "Distribution status changed to {$status}",
            'metadata' => [
                'distribution_id' => $distribution->id,
                'song_id' => $distribution->song_id,
                'platform' => $distribution->platform_code,
                'status' => $status,
            ],
        ]);
    }
}