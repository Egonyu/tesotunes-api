<?php

namespace App\Http\Controllers\Api;

use App\Enums\AdPlacement;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdImpression;
use App\Models\AdPlacementAssignment;
use App\Models\AdPlacementConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdServingController extends Controller
{
    /**
     * GET /api/ads
     *
     * Returns a single eligible ad for the requested placement zone, applying:
     *   - Zone enabled check
     *   - Tier targeting (free users only by default)
     *   - Device targeting
     *   - Country targeting
     *   - Frequency cap (authenticated users)
     *   - Weighted random selection among eligible assignments
     */
    public function serve(Request $request): JsonResponse
    {
        if (! config('ads.enabled')) {
            return response()->json(['data' => null]);
        }

        $validated = $request->validate([
            'placement' => ['required', 'string', Rule::in(AdPlacement::values())],
            'device' => ['sometimes', 'string', Rule::in(['desktop', 'mobile', 'tablet'])],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        $placementKey = $validated['placement'];
        $device = $validated['device'] ?? 'desktop';
        $country = $validated['country'] ?? null;
        $user = $request->user();

        $tier = 'free';
        if ($user) {
            $tier = $user->subscription?->tier ?? 'free';
        }

        $config = AdPlacementConfig::where('placement_key', $placementKey)->first();

        if (! $config || ! $config->is_enabled) {
            return response()->json(['data' => null]);
        }

        // Zone-level tier gate — if target_tiers is set and tier not in it, serve nothing
        if ($config->target_tiers && ! in_array($tier, $config->target_tiers)) {
            return response()->json(['data' => null]);
        }

        // Frequency cap — authenticated users only
        if ($user && $config->frequency_cap_per_day > 0) {
            $seenToday = AdImpression::where('user_id', $user->id)
                ->where('placement_key', $placementKey)
                ->whereDate('created_at', today())
                ->count();

            if ($seenToday >= $config->frequency_cap_per_day) {
                return response()->json(['data' => null]);
            }
        }

        // Build eligible ads query
        $query = $config->eligibleAds($tier, $device);

        if ($country) {
            $query->forCountry($country);
        }

        $ads = $query->get([
            'id', 'type', 'format',
            'image_url', 'click_url', 'cta_text',
            'html_content',
            'audio_url', 'audio_duration_seconds',
            'native_headline', 'native_body', 'native_image_url',
            'adsense_slot_id', 'adsense_format',
        ]);

        if ($ads->isEmpty()) {
            return response()->json(['data' => null]);
        }

        // Load assignment weights for weighted random rotation
        $weights = AdPlacementAssignment::where('placement_key', $placementKey)
            ->whereIn('ad_id', $ads->pluck('id'))
            ->active()
            ->pluck('weight', 'ad_id');

        $selected = $this->weightedRandom($ads, $weights->all());

        return response()->json([
            'data' => [
                'id' => $selected->id,
                'type' => $selected->type,
                'format' => $selected->format,
                'image_url' => $selected->image_url,
                'click_url' => $selected->click_url,
                'cta_text' => $selected->cta_text,
                'html_content' => $selected->html_content,
                'audio_url' => $selected->audio_url,
                'audio_duration_seconds' => $selected->audio_duration_seconds,
                'native_headline' => $selected->native_headline,
                'native_body' => $selected->native_body,
                'native_image_url' => $selected->native_image_url,
                'adsense_slot_id' => $selected->adsense_slot_id,
                'adsense_format' => $selected->adsense_format,
                'placement_key' => $placementKey,
            ],
        ]);
    }

    /**
     * Select one ad using weighted random from the eligible set.
     *
     * @param  Collection<int, Ad>  $ads
     * @param  array<int, int>  $weights  ad_id → weight
     */
    private function weightedRandom(Collection $ads, array $weights): Model
    {
        $totalWeight = 0;
        $resolved = [];

        foreach ($ads as $ad) {
            $w = $weights[$ad->id] ?? 10;
            $totalWeight += $w;
            $resolved[$ad->id] = $w;
        }

        if ($totalWeight === 0) {
            return $ads->first();
        }

        $random = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($ads as $ad) {
            $cumulative += $resolved[$ad->id];
            if ($random <= $cumulative) {
                return $ad;
            }
        }

        return $ads->first();
    }
}
