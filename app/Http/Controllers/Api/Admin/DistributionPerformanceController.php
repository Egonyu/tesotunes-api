<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Distribution;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DistributionPerformanceController extends Controller
{
    use HandlesApiErrors;

    /**
     * Get admin overview of distribution performance across all artists
     */
    public function performance(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $days = min((int) $request->get('days', 30), 365);
            $cacheKey = "admin:distribution:performance:{$days}";

            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($days) {
                $since = now()->subDays($days);

                $distributions = Distribution::with(['song:id,title,artist_id', 'artist:id,name,stage_name'])
                    ->where('created_at', '>=', $since)
                    ->get();

                $allDistributions = Distribution::query();

                return [
                    'summary' => [
                        'total_distributions' => $allDistributions->count(),
                        'live' => (clone $allDistributions)->where('status', 'live')->count(),
                        'pending' => (clone $allDistributions)->where('status', 'pending')->count(),
                        'failed' => (clone $allDistributions)->where('status', 'failed')->count(),
                        'rejected' => (clone $allDistributions)->where('status', 'rejected')->count(),
                        'removed' => (clone $allDistributions)->where('status', 'removed')->count(),
                    ],
                    'period' => [
                        'days' => $days,
                        'new_distributions' => $distributions->count(),
                        'total_streams' => $distributions->sum('total_streams'),
                        'total_revenue' => number_format($distributions->sum('total_revenue'), 2),
                    ],
                    'platform_breakdown' => $distributions->groupBy('platform_code')->map(function ($group) {
                        return [
                            'platform' => $group->first()->platform_name,
                            'count' => $group->count(),
                            'live' => $group->where('status', 'live')->count(),
                            'failed' => $group->where('status', 'failed')->count(),
                            'streams' => $group->sum('total_streams'),
                            'revenue' => number_format($group->sum('total_revenue'), 2),
                        ];
                    })->values(),
                    'top_performing_songs' => Distribution::where('status', 'live')
                        ->with(['song:id,title', 'artist:id,name,stage_name'])
                        ->orderByDesc('total_streams')
                        ->limit(10)
                        ->get()
                        ->map(fn ($d) => [
                            'song' => $d->song?->title,
                            'artist' => $d->artist?->stage_name ?? $d->artist?->name,
                            'platform' => $d->platform_name,
                            'streams' => $d->formatted_streams,
                            'revenue' => $d->formatted_revenue,
                        ]),
                    'recent_failures' => Distribution::whereIn('status', ['failed', 'rejected'])
                        ->with(['song:id,title', 'artist:id,name,stage_name'])
                        ->orderByDesc('updated_at')
                        ->limit(10)
                        ->get()
                        ->map(fn ($d) => [
                            'song' => $d->song?->title,
                            'artist' => $d->artist?->stage_name ?? $d->artist?->name,
                            'platform' => $d->platform_name,
                            'status' => $d->status,
                            'error' => $d->error_message ?? $d->rejection_reason,
                            'date' => $d->updated_at?->format('Y-m-d'),
                        ]),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to load distribution performance data.');
    }
}
