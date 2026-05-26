<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdImpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdTrackingController extends Controller
{
    /**
     * POST /api/ads/impression
     *
     * Records that a user was served an ad. Fired by the frontend on mount.
     */
    public function recordImpression(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ad_id' => ['required', 'integer', 'exists:ads,id'],
            'placement_key' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        AdImpression::create([
            'ad_id' => $validated['ad_id'],
            'placement_key' => $validated['placement_key'] ?? null,
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'device_type' => $this->detectDevice($request),
            'page_url' => $validated['page_url'] ?? null,
            'clicked' => false,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/ads/click
     *
     * Marks the most recent unclicked impression for this ad as clicked.
     */
    public function recordClick(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ad_id' => ['required', 'integer', 'exists:ads,id'],
            'placement_key' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $query = AdImpression::where('ad_id', $validated['ad_id'])
            ->where('clicked', false)
            ->whereDate('created_at', today())
            ->latest('created_at');

        // Prefer matching by user when authenticated, fall back to IP
        if ($request->user()) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->where('ip_address', $request->ip());
        }

        $impression = $query->first();

        if ($impression) {
            $impression->update([
                'clicked' => true,
                'clicked_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function detectDevice(Request $request): string
    {
        $ua = strtolower($request->userAgent() ?? '');

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }
}
