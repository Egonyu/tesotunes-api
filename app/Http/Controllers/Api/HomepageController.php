<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomepageService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomepageController extends Controller
{
    use HandlesApiErrors;

    public function index(Request $request, HomepageService $homepageService)
    {
        return $this->handleApiAction(function () use ($request, $homepageService) {
            $mode = $this->resolveMode($request->query('mode'));
            $user = $request->user();

            // Cache anonymous (unauthenticated) homepage responses for 5 minutes.
            // Authenticated responses are always personalized — never cache those.
            if (! $user) {
                $cacheKey = "api:homepage:anon:{$mode}";
                $data = Cache::remember($cacheKey, 300, fn () => $homepageService->build(null, $mode));
            } else {
                $data = $homepageService->build($user, $mode);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to build homepage.');
    }

    private function resolveMode(?string $requestedMode): string
    {
        return match ($requestedMode) {
            'music', 'radio', 'uganda', 'fresh' => $requestedMode,
            default => 'all',
        };
    }
}
