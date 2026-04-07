<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomepageService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;

class HomepageController extends Controller
{
    use HandlesApiErrors;

    public function index(Request $request, HomepageService $homepageService)
    {
        return $this->handleApiAction(function () use ($request, $homepageService) {
            $mode = $this->resolveMode($request->query('mode'));

            return response()->json([
                'success' => true,
                'data' => $homepageService->build($request->user(), $mode),
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
