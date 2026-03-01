<?php

namespace App\Http\Controllers;

use App\Models\Distribution;
use App\Services\DistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DistributionWebhookController extends Controller
{
    protected DistributionService $distributionService;

    public function __construct(DistributionService $distributionService)
    {
        $this->distributionService = $distributionService;
    }

    /**
     * Handle incoming distribution platform webhooks
     *
     * Receives status updates from Spotify, Apple Music, etc.
     * Route: POST /api/webhooks/distribution/{platform}
     */
    public function handle(Request $request, string $platform): JsonResponse
    {
        try {
            if (! array_key_exists($platform, DistributionService::PLATFORMS)) {
                return response()->json([
                    'success' => false,
                    'message' => "Unsupported platform: {$platform}",
                ], 422);
            }

            Log::info("Distribution webhook received from {$platform}", [
                'platform' => $platform,
                'payload_keys' => array_keys($request->all()),
            ]);

            $payload = $request->all();
            $eventType = $payload['event'] ?? $payload['type'] ?? $payload['action'] ?? 'unknown';

            return match ($eventType) {
                'status_update', 'distribution.status_changed' => $this->handleStatusUpdate($platform, $payload),
                'live', 'distribution.live' => $this->handleGoLive($platform, $payload),
                'failed', 'distribution.failed' => $this->handleFailure($platform, $payload),
                'rejected', 'distribution.rejected' => $this->handleRejection($platform, $payload),
                'removed', 'distribution.removed' => $this->handleRemoval($platform, $payload),
                'analytics', 'distribution.analytics' => $this->handleAnalyticsUpdate($platform, $payload),
                default => $this->handleGenericEvent($platform, $eventType, $payload),
            };
        } catch (\Exception $e) {
            Log::error("Distribution webhook error for {$platform}: {$e->getMessage()}", [
                'platform' => $platform,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
            ], 500);
        }
    }

    protected function handleStatusUpdate(string $platform, array $payload): JsonResponse
    {
        $platformId = $payload['platform_id'] ?? $payload['external_id'] ?? null;
        $newStatus = $payload['status'] ?? $payload['new_status'] ?? null;

        if (! $platformId || ! $newStatus) {
            return response()->json([
                'success' => false,
                'message' => 'Missing platform_id or status',
            ], 422);
        }

        $distribution = Distribution::where('platform_code', $platform)
            ->where('platform_id', $platformId)
            ->first();

        if (! $distribution) {
            return response()->json([
                'success' => false,
                'message' => 'Distribution not found',
            ], 404);
        }

        $this->distributionService->updateDistributionStatus($distribution, $newStatus, $payload);

        return response()->json([
            'success' => true,
            'message' => "Status updated to {$newStatus}",
        ]);
    }

    protected function handleGoLive(string $platform, array $payload): JsonResponse
    {
        $distribution = $this->findDistribution($platform, $payload);

        if (! $distribution) {
            return response()->json(['success' => false, 'message' => 'Distribution not found'], 404);
        }

        $this->distributionService->updateDistributionStatus($distribution, 'live', [
            'platform_url' => $payload['url'] ?? $payload['platform_url'] ?? null,
            'platform_id' => $payload['platform_id'] ?? $payload['external_id'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Distribution marked as live']);
    }

    protected function handleFailure(string $platform, array $payload): JsonResponse
    {
        $distribution = $this->findDistribution($platform, $payload);

        if (! $distribution) {
            return response()->json(['success' => false, 'message' => 'Distribution not found'], 404);
        }

        $this->distributionService->updateDistributionStatus($distribution, 'failed', [
            'error_message' => $payload['error'] ?? $payload['message'] ?? 'Unknown failure',
        ]);

        return response()->json(['success' => true, 'message' => 'Distribution marked as failed']);
    }

    protected function handleRejection(string $platform, array $payload): JsonResponse
    {
        $distribution = $this->findDistribution($platform, $payload);

        if (! $distribution) {
            return response()->json(['success' => false, 'message' => 'Distribution not found'], 404);
        }

        $this->distributionService->updateDistributionStatus($distribution, 'rejected', [
            'rejection_reason' => $payload['reason'] ?? $payload['rejection_reason'] ?? 'Not specified',
        ]);

        return response()->json(['success' => true, 'message' => 'Distribution marked as rejected']);
    }

    protected function handleRemoval(string $platform, array $payload): JsonResponse
    {
        $distribution = $this->findDistribution($platform, $payload);

        if (! $distribution) {
            return response()->json(['success' => false, 'message' => 'Distribution not found'], 404);
        }

        $this->distributionService->updateDistributionStatus($distribution, 'removed', [
            'removal_reason' => $payload['reason'] ?? 'Platform removed',
        ]);

        return response()->json(['success' => true, 'message' => 'Distribution marked as removed']);
    }

    protected function handleAnalyticsUpdate(string $platform, array $payload): JsonResponse
    {
        $distribution = $this->findDistribution($platform, $payload);

        if (! $distribution) {
            return response()->json(['success' => false, 'message' => 'Distribution not found'], 404);
        }

        $distribution->update([
            'total_streams' => $payload['streams'] ?? $distribution->total_streams,
            'total_revenue' => $payload['revenue'] ?? $distribution->total_revenue,
            'last_synced' => now(),
            'platform_metadata' => array_merge(
                $distribution->platform_metadata ?? [],
                ['last_analytics_update' => now()->toIso8601String()]
            ),
        ]);

        return response()->json(['success' => true, 'message' => 'Analytics updated']);
    }

    protected function handleGenericEvent(string $platform, string $eventType, array $payload): JsonResponse
    {
        Log::info("Unhandled distribution webhook event: {$eventType}", [
            'platform' => $platform,
            'event' => $eventType,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Event '{$eventType}' acknowledged",
        ]);
    }

    /**
     * Find distribution by platform identifiers in the webhook payload
     */
    protected function findDistribution(string $platform, array $payload): ?Distribution
    {
        $platformId = $payload['platform_id'] ?? $payload['external_id'] ?? null;

        if ($platformId) {
            $dist = Distribution::where('platform_code', $platform)
                ->where('platform_id', $platformId)
                ->first();

            if ($dist) {
                return $dist;
            }
        }

        // Fallback: match by song_id + platform if provided
        $songId = $payload['song_id'] ?? $payload['internal_id'] ?? null;
        if ($songId) {
            return Distribution::where('platform_code', $platform)
                ->where('song_id', $songId)
                ->first();
        }

        return null;
    }
}
