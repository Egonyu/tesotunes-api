<?php

namespace App\Http\Controllers;

use App\Models\Distribution;
use App\Models\Song;
use App\Models\Album;
use App\Services\DistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DistributionController extends Controller
{
    protected DistributionService $distributionService;

    public function __construct(DistributionService $distributionService)
    {
        $this->distributionService = $distributionService;
    }

    /**
     * Submit a song for distribution to selected platforms
     */
    public function submitSongDistribution(Request $request, Song $song): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:' . implode(',', array_keys(DistributionService::PLATFORMS)),
            'release_date' => 'nullable|date|after:today',
            'territories' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();

            // Verify ownership: song must belong to the authenticated artist
            if ($song->user_id !== $user->id && $song->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this song',
                ], 403);
            }

            $result = $this->distributionService->distributeMusic(
                $song,
                $request->platforms,
                $request->only(['release_date', 'territories'])
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'distributions' => $result['distributions'],
                    'estimated_delivery' => $result['estimated_delivery'],
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit distribution',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all distributions for a song
     */
    public function getSongDistributions(Request $request, Song $song): JsonResponse
    {
        try {
            $user = $request->user();

            if ($song->user_id !== $user->id && $song->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this song',
                ], 403);
            }

            $distributions = Distribution::where('song_id', $song->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($dist) {
                    return [
                        'id' => $dist->id,
                        'platform_code' => $dist->platform_code,
                        'platform_name' => $dist->platform_name,
                        'status' => $dist->status,
                        'platform_url' => $dist->platform_url,
                        'live_date' => $dist->live_date?->format('Y-m-d'),
                        'total_streams' => $dist->formatted_streams,
                        'total_revenue' => $dist->formatted_revenue,
                        'last_synced' => $dist->last_synced?->diffForHumans(),
                        'error_message' => $dist->error_message,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $distributions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch distributions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request removal of a song from a distribution platform
     */
    public function requestRemoval(Request $request, Song $song, Distribution $distribution): JsonResponse
    {
        try {
            $user = $request->user();

            if ($song->user_id !== $user->id && $song->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this song',
                ], 403);
            }

            if ($distribution->song_id !== $song->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distribution does not belong to this song',
                ], 422);
            }

            $results = $this->distributionService->removeFromDistribution($song, [$distribution->platform_code]);

            return response()->json([
                'success' => true,
                'message' => 'Removal request submitted',
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to request removal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Distribute an entire album to platforms
     */
    public function distributeAlbum(Request $request, Album $album): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:' . implode(',', array_keys(DistributionService::PLATFORMS)),
            'release_date' => 'nullable|date|after:today',
            'territories' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();

            if ($album->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not own this album',
                ], 403);
            }

            $songs = $album->songs()->where('status', 'published')->get();

            if ($songs->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Album has no published songs to distribute',
                ], 422);
            }

            $allResults = [];
            $distributionData = $request->only(['release_date', 'territories']);

            foreach ($songs as $song) {
                try {
                    $result = $this->distributionService->distributeMusic(
                        $song,
                        $request->platforms,
                        $distributionData
                    );
                    $allResults[] = [
                        'song' => $song->title,
                        'status' => 'submitted',
                        'distributions' => count($result['distributions']),
                    ];
                } catch (\Exception $e) {
                    $allResults[] = [
                        'song' => $song->title,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Album distribution submitted for {$songs->count()} songs",
                'data' => $allResults,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to distribute album',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk submit multiple songs for distribution
     */
    public function bulkSubmit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'song_ids' => 'required|array|min:1|max:50',
            'song_ids.*' => 'integer|exists:songs,id',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:' . implode(',', array_keys(DistributionService::PLATFORMS)),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $results = [];

            foreach ($request->song_ids as $songId) {
                $song = Song::find($songId);

                if (! $song || ($song->user_id !== $user->id && $song->artist?->user_id !== $user->id)) {
                    $results[] = ['song_id' => $songId, 'status' => 'skipped', 'reason' => 'Not found or not owned'];
                    continue;
                }

                try {
                    $result = $this->distributionService->distributeMusic($song, $request->platforms);
                    $results[] = ['song_id' => $songId, 'title' => $song->title, 'status' => 'submitted'];
                } catch (\Exception $e) {
                    $results[] = ['song_id' => $songId, 'title' => $song->title, 'status' => 'failed', 'error' => $e->getMessage()];
                }
            }

            $submitted = collect($results)->where('status', 'submitted')->count();

            return response()->json([
                'success' => true,
                'message' => "{$submitted} of " . count($request->song_ids) . " songs submitted for distribution",
                'data' => $results,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk distribution failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get distribution status for a specific distribution
     */
    public function getStatus(Request $request, Distribution $distribution): JsonResponse
    {
        try {
            $user = $request->user();

            if ($distribution->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this distribution',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $distribution->id,
                    'song' => $distribution->song?->title,
                    'platform_code' => $distribution->platform_code,
                    'platform_name' => $distribution->platform_name,
                    'status' => $distribution->status,
                    'platform_url' => $distribution->platform_url,
                    'live_date' => $distribution->live_date?->format('Y-m-d'),
                    'error_message' => $distribution->error_message,
                    'rejection_reason' => $distribution->rejection_reason,
                    'total_streams' => $distribution->formatted_streams,
                    'total_revenue' => $distribution->formatted_revenue,
                    'last_synced' => $distribution->last_synced?->diffForHumans(),
                    'metadata' => $distribution->distribution_metadata,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get distribution status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request removal of a specific distribution
     */
    public function requestDistributionRemoval(Request $request, Distribution $distribution): JsonResponse
    {
        try {
            $user = $request->user();

            if ($distribution->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this distribution',
                ], 403);
            }

            $results = $this->distributionService->removeFromDistribution(
                $distribution->song,
                [$distribution->platform_code]
            );

            return response()->json([
                'success' => true,
                'message' => 'Removal request submitted',
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to request removal',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry a failed distribution
     */
    public function retryDistribution(Request $request, Distribution $distribution): JsonResponse
    {
        try {
            $user = $request->user();

            if ($distribution->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this distribution',
                ], 403);
            }

            if (! in_array($distribution->status, ['failed', 'rejected'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed or rejected distributions can be retried',
                ], 422);
            }

            $this->distributionService->updateDistributionStatus($distribution, 'pending');
            dispatch(new \App\Jobs\ProcessDistribution($distribution));

            return response()->json([
                'success' => true,
                'message' => 'Distribution retried — queued for processing',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry distribution',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get royalty report for a specific distribution
     */
    public function getRoyaltyReport(Request $request, Distribution $distribution): JsonResponse
    {
        try {
            $user = $request->user();

            if ($distribution->artist?->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this distribution',
                ], 403);
            }

            $royalties = $this->distributionService->calculateRoyalties(
                $distribution,
                (float) ($distribution->total_revenue ?? 0)
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'distribution' => [
                        'song' => $distribution->song?->title,
                        'platform' => $distribution->platform_name,
                        'status' => $distribution->status,
                    ],
                    'royalties' => $royalties,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate royalty report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get artist distribution analytics dashboard
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $artist = $user->artist;

            if (! $artist) {
                return response()->json([
                    'success' => false,
                    'message' => 'No artist profile found',
                ], 404);
            }

            $distributions = $this->distributionService->getArtistDistributions($artist);

            $analytics = [
                'total_distributions' => $distributions->count(),
                'live' => $distributions->where('status', 'live')->count(),
                'pending' => $distributions->where('status', 'pending')->count(),
                'failed' => $distributions->where('status', 'failed')->count(),
                'total_streams' => $distributions->sum('total_streams'),
                'total_revenue' => number_format($distributions->sum('total_revenue'), 2),
                'platforms' => $distributions->groupBy('platform_code')->map(function ($group) {
                    return [
                        'platform' => $group->first()->platform_name,
                        'count' => $group->count(),
                        'live' => $group->where('status', 'live')->count(),
                        'streams' => $group->sum('total_streams'),
                        'revenue' => number_format($group->sum('total_revenue'), 2),
                    ];
                })->values(),
                'recent_distributions' => $distributions->take(10)->map(function ($dist) {
                    return [
                        'id' => $dist->id,
                        'song' => $dist->song?->title,
                        'platform' => $dist->platform_name,
                        'status' => $dist->status,
                        'live_date' => $dist->live_date?->format('Y-m-d'),
                        'streams' => $dist->formatted_streams,
                        'revenue' => $dist->formatted_revenue,
                    ];
                }),
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load distribution analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
