<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdPlacement;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdImpression;
use App\Models\AdPlacementAssignment;
use App\Models\AdPlacementConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AdminAdPlacementController extends Controller
{
    private const VALID_TIERS = ['free', 'premium_basic', 'premium', 'artist', 'label'];

    private const VALID_FORMATS = ['image', 'html', 'audio', 'native', 'google_adsense'];

    private const VALID_DEVICES = ['all', 'desktop', 'mobile'];

    // ── Zone listing + detail ─────────────────────────────────────────────────

    /** GET /admin/ad-placements — all 16 zones with stats */
    public function index(): JsonResponse
    {
        try {
            $configs = AdPlacementConfig::withCount([
                'assignments',
                'assignments as active_assignments_count' => fn ($q) => $q->active(),
            ])->get();

            // Impression counts per zone for the last 7 days
            $zoneCounts = AdImpression::where('created_at', '>=', now()->subDays(7))
                ->select('placement_key', DB::raw('COUNT(*) as impressions_7d'))
                ->groupBy('placement_key')
                ->pluck('impressions_7d', 'placement_key');

            return response()->json([
                'data' => $configs->map(fn (AdPlacementConfig $c) => $this->serializeConfig($c, $zoneCounts)),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to load placement zones.'], 500);
        }
    }

    /** GET /admin/ad-placements/{key} — single zone detail with assignments */
    public function show(string $key): JsonResponse
    {
        try {
            $config = AdPlacementConfig::where('placement_key', $key)->firstOrFail();

            $assignments = $config->assignments()
                ->with('ad:id,title,advertiser_name,type,format,is_active')
                ->orderByDesc('priority')
                ->orderByDesc('weight')
                ->get()
                ->map(fn (AdPlacementAssignment $a) => $this->serializeAssignment($a));

            return response()->json([
                'data' => array_merge(
                    $this->serializeConfig($config),
                    ['assignments' => $assignments]
                ),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Placement zone not found.'], 404);
        }
    }

    /** PUT /admin/ad-placements/{key} — update zone config */
    public function update(Request $request, string $key): JsonResponse
    {
        try {
            $config = AdPlacementConfig::where('placement_key', $key)->firstOrFail();

            $validated = $request->validate([
                'is_enabled' => ['sometimes', 'boolean'],
                'device_type' => ['sometimes', Rule::in(self::VALID_DEVICES)],
                'allowed_formats' => ['sometimes', 'array'],
                'allowed_formats.*' => [Rule::in(self::VALID_FORMATS)],
                'dimensions_width' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'dimensions_height' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'target_tiers' => ['sometimes', 'nullable', 'array'],
                'target_tiers.*' => [Rule::in(self::VALID_TIERS)],
                'frequency_cap_per_day' => ['sometimes', 'integer', 'min:0', 'max:100'],
                'max_ads_per_page' => ['sometimes', 'integer', 'min:1', 'max:10'],
                'notes' => ['sometimes', 'nullable', 'string'],
            ]);

            $config->update(array_merge($validated, ['updated_by' => $request->user()->id]));

            return response()->json(['data' => $this->serializeConfig($config->fresh())]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to update placement zone.'], 500);
        }
    }

    // ── Assignment management ─────────────────────────────────────────────────

    /** POST /admin/ad-placements/{key}/assign */
    public function assign(Request $request, string $key): JsonResponse
    {
        try {
            AdPlacementConfig::where('placement_key', $key)->firstOrFail();

            $validated = $request->validate([
                'ad_id' => ['required', 'integer', 'exists:ads,id'],
                'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
                'weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'is_active' => ['sometimes', 'boolean'],
                'starts_at' => ['sometimes', 'nullable', 'date'],
                'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            ]);

            // Verify ad exists and is not trashed
            $ad = Ad::findOrFail($validated['ad_id']);

            $assignment = AdPlacementAssignment::updateOrCreate(
                ['ad_id' => $ad->id, 'placement_key' => $key],
                [
                    'priority' => $validated['priority'] ?? 5,
                    'weight' => $validated['weight'] ?? 10,
                    'is_active' => $validated['is_active'] ?? true,
                    'starts_at' => $validated['starts_at'] ?? null,
                    'ends_at' => $validated['ends_at'] ?? null,
                ]
            );

            return response()->json(['data' => $this->serializeAssignment($assignment->load('ad'))], 201);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to assign ad to zone.'], 500);
        }
    }

    /** PUT /admin/ad-placements/{key}/assign/{assignment} — update weight/priority */
    public function updateAssignment(Request $request, string $key, AdPlacementAssignment $assignment): JsonResponse
    {
        try {
            if ($assignment->placement_key !== $key) {
                return response()->json(['error' => 'Assignment does not belong to this zone.'], 422);
            }

            $validated = $request->validate([
                'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
                'weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'is_active' => ['sometimes', 'boolean'],
                'starts_at' => ['sometimes', 'nullable', 'date'],
                'ends_at' => ['sometimes', 'nullable', 'date'],
            ]);

            $assignment->update($validated);

            return response()->json(['data' => $this->serializeAssignment($assignment->fresh()->load('ad'))]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to update assignment.'], 500);
        }
    }

    /** DELETE /admin/ad-placements/{key}/assign/{assignment} */
    public function removeAssignment(string $key, AdPlacementAssignment $assignment): JsonResponse
    {
        try {
            if ($assignment->placement_key !== $key) {
                return response()->json(['error' => 'Assignment does not belong to this zone.'], 422);
            }

            $assignment->delete();

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to remove assignment.'], 500);
        }
    }

    // ── Zone analytics ────────────────────────────────────────────────────────

    /** GET /admin/ad-placements/analytics */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $days = max(1, min((int) $request->integer('days', 7), 90));
            $since = now()->subDays($days);

            $rows = AdImpression::where('created_at', '>=', $since)
                ->select(
                    'placement_key',
                    DB::raw('COUNT(*) as impressions'),
                    DB::raw('SUM(clicked) as clicks')
                )
                ->groupBy('placement_key')
                ->get()
                ->map(fn ($row) => [
                    'placement_key' => $row->placement_key,
                    'label' => AdPlacement::tryFrom($row->placement_key)?->label() ?? $row->placement_key,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'ctr' => $row->impressions > 0
                        ? round($row->clicks / $row->impressions * 100, 2)
                        : 0,
                ]);

            return response()->json(['data' => $rows, 'days' => $days]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to load zone analytics.'], 500);
        }
    }

    // ── Serializers ───────────────────────────────────────────────────────────

    private function serializeConfig(AdPlacementConfig $c, $zoneCounts = []): array
    {
        $enum = $c->placementEnum();

        return [
            'placement_key' => $c->placement_key,
            'label' => $c->label,
            'description' => $c->description,
            'device_type' => $c->device_type,
            'allowed_formats' => $c->allowed_formats,
            'dimensions_width' => $c->dimensions_width,
            'dimensions_height' => $c->dimensions_height,
            'is_enabled' => $c->is_enabled,
            'target_tiers' => $c->target_tiers,
            'frequency_cap_per_day' => $c->frequency_cap_per_day,
            'max_ads_per_page' => $c->max_ads_per_page,
            'notes' => $c->notes,
            'is_audio' => $enum?->isAudio() ?? false,
            'assignments_count' => $c->assignments_count ?? null,
            'active_assignments_count' => $c->active_assignments_count ?? null,
            'impressions_7d' => (int) ($zoneCounts[$c->placement_key] ?? 0),
            'updated_at' => $c->updated_at?->toISOString(),
        ];
    }

    private function serializeAssignment(AdPlacementAssignment $a): array
    {
        return [
            'id' => $a->id,
            'ad_id' => $a->ad_id,
            'ad_title' => $a->ad?->title,
            'ad_type' => $a->ad?->type,
            'ad_format' => $a->ad?->format,
            'ad_is_active' => $a->ad?->is_active,
            'advertiser' => $a->ad?->advertiser_name,
            'placement_key' => $a->placement_key,
            'priority' => $a->priority,
            'weight' => $a->weight,
            'is_active' => $a->is_active,
            'starts_at' => $a->starts_at?->toISOString(),
            'ends_at' => $a->ends_at?->toISOString(),
            'created_at' => $a->created_at?->toISOString(),
        ];
    }
}
