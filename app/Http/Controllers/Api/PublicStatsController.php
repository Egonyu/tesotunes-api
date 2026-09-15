<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Real platform counts for marketing surfaces.
 *
 * The sign-in, join and become-artist pages claimed "10K+ songs", "50K+
 * users", "millions of songs" and "100K+ monthly listeners" as fixed copy,
 * against a catalogue of a few hundred songs. They read these numbers instead.
 */
class PublicStatsController extends Controller
{
    private const CACHE_KEY = 'public_stats:v1';

    private const CACHE_SECONDS = 3600;

    /**
     * GET /api/public/stats
     */
    public function index(): JsonResponse
    {
        try {
            $stats = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => [
                'songs' => Song::query()->published()->count(),
                // An artist counts once they have something anyone can play.
                'artists' => Artist::query()
                    ->whereHas('songs', fn ($query) => $query->where('status', 'published'))
                    ->count(),
                'members' => User::query()->count(),
            ]);

            return response()->json(['success' => true, 'data' => $stats]);
        } catch (\Throwable $e) {
            Log::warning('public_stats.failed', ['error' => $e->getMessage()]);

            // Surfaces hide the figures on failure rather than inventing them.
            return response()->json(['success' => false, 'data' => null], 503);
        }
    }
}
