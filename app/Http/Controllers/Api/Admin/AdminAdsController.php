<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdImpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AdminAdsController extends Controller
{
    private const AD_TYPES = ['image', 'html', 'audio', 'native', 'google_adsense'];

    private const AD_FORMATS = ['banner_728x90', 'banner_320x50', 'square_300x250', 'native', 'audio', 'html'];

    private const VALID_TIERS = ['free', 'premium_basic', 'premium', 'artist', 'label'];

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
            $search = trim((string) $request->input('search', ''));
            $type = trim((string) $request->input('type', ''));
            $status = $request->input('status');

            $query = Ad::withTrashed()
                ->withCount('impressions')
                ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('advertiser_name', 'like', "%{$search}%");
                }))
                ->when($type !== '' && in_array($type, self::AD_TYPES, true), fn ($q) => $q->where('type', $type))
                ->when($status === 'active', fn ($q) => $q->active())
                ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
                ->when($status === 'trashed', fn ($q) => $q->onlyTrashed())
                ->when($status === null || $status === 'all', fn ($q) => $q)
                ->latest()
                ->paginate($perPage);

            return response()->json([
                'data' => $query->getCollection()->map(fn (Ad $ad) => $this->serializeListItem($ad)),
                'meta' => [
                    'current_page' => $query->currentPage(),
                    'last_page' => $query->lastPage(),
                    'per_page' => $query->perPage(),
                    'total' => $query->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to fetch ads.'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->validationRules());

            $ad = Ad::create(array_merge($validated, [
                'created_by' => $request->user()->id,
            ]));

            return response()->json(['data' => $this->serializeDetail($ad)], 201);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to create ad.'], 500);
        }
    }

    public function show(Ad $ad): JsonResponse
    {
        try {
            $ad->loadCount('impressions');

            $clickCount = $ad->impressions()->where('clicked', true)->count();
            $impressionCount = $ad->impressions_count;

            return response()->json([
                'data' => array_merge($this->serializeDetail($ad), [
                    'stats' => [
                        'impressions' => $impressionCount,
                        'clicks' => $clickCount,
                        'ctr' => $impressionCount > 0
                            ? round($clickCount / $impressionCount * 100, 2)
                            : 0,
                    ],
                    'assignments' => $ad->assignments()->with('placementConfig')->get()->map(fn ($a) => [
                        'id' => $a->id,
                        'placement_key' => $a->placement_key,
                        'label' => $a->placementConfig?->label,
                        'priority' => $a->priority,
                        'weight' => $a->weight,
                        'is_active' => $a->is_active,
                        'starts_at' => $a->starts_at?->toISOString(),
                        'ends_at' => $a->ends_at?->toISOString(),
                    ]),
                ]),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to load ad.'], 500);
        }
    }

    public function update(Request $request, Ad $ad): JsonResponse
    {
        try {
            $validated = $request->validate($this->validationRules(updating: true));
            $ad->update($validated);

            return response()->json(['data' => $this->serializeDetail($ad->fresh())]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to update ad.'], 500);
        }
    }

    public function destroy(Ad $ad): JsonResponse
    {
        try {
            $ad->delete();

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to delete ad.'], 500);
        }
    }

    public function activate(Ad $ad): JsonResponse
    {
        try {
            $ad->update(['is_active' => true]);

            return response()->json(['data' => ['is_active' => true]]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to activate ad.'], 500);
        }
    }

    public function pause(Ad $ad): JsonResponse
    {
        try {
            $ad->update(['is_active' => false]);

            return response()->json(['data' => ['is_active' => false]]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to pause ad.'], 500);
        }
    }

    // ── Analytics ─────────────────────────────────────────────────────────────

    public function analytics(Request $request): JsonResponse
    {
        try {
            $days = max(1, min((int) $request->integer('days', 30), 90));
            $since = now()->subDays($days);

            $rows = AdImpression::where('created_at', '>=', $since)
                ->select('ad_id',
                    DB::raw('COUNT(*) as impressions'),
                    DB::raw('SUM(clicked) as clicks')
                )
                ->groupBy('ad_id')
                ->with('ad:id,title,advertiser_name,type,is_active')
                ->get()
                ->map(fn ($row) => [
                    'ad_id' => $row->ad_id,
                    'title' => $row->ad?->title,
                    'advertiser' => $row->ad?->advertiser_name,
                    'type' => $row->ad?->type,
                    'is_active' => (bool) $row->ad?->is_active,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'ctr' => $row->impressions > 0
                        ? round($row->clicks / $row->impressions * 100, 2)
                        : 0,
                ]);

            return response()->json(['data' => $rows, 'days' => $days]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Failed to load analytics.'], 500);
        }
    }

    // ── Serializers ───────────────────────────────────────────────────────────

    private function serializeListItem(Ad $ad): array
    {
        return [
            'id' => $ad->id,
            'uuid' => $ad->uuid,
            'title' => $ad->title,
            'advertiser_name' => $ad->advertiser_name,
            'type' => $ad->type,
            'format' => $ad->format,
            'is_active' => $ad->is_active,
            'starts_at' => $ad->starts_at?->toISOString(),
            'ends_at' => $ad->ends_at?->toISOString(),
            'impressions' => $ad->impressions_count ?? 0,
            'deleted_at' => $ad->deleted_at?->toISOString(),
            'created_at' => $ad->created_at?->toISOString(),
        ];
    }

    private function serializeDetail(Ad $ad): array
    {
        return [
            'id' => $ad->id,
            'uuid' => $ad->uuid,
            'title' => $ad->title,
            'advertiser_name' => $ad->advertiser_name,
            'type' => $ad->type,
            'format' => $ad->format,
            'image_url' => $ad->image_url,
            'click_url' => $ad->click_url,
            'cta_text' => $ad->cta_text,
            'html_content' => $ad->html_content,
            'audio_url' => $ad->audio_url,
            'audio_duration_seconds' => $ad->audio_duration_seconds,
            'native_headline' => $ad->native_headline,
            'native_body' => $ad->native_body,
            'native_image_url' => $ad->native_image_url,
            'adsense_slot_id' => $ad->adsense_slot_id,
            'adsense_format' => $ad->adsense_format,
            'is_active' => $ad->is_active,
            'starts_at' => $ad->starts_at?->toISOString(),
            'ends_at' => $ad->ends_at?->toISOString(),
            'total_budget_ugx' => $ad->total_budget_ugx,
            'daily_budget_ugx' => $ad->daily_budget_ugx,
            'cost_per_impression_ugx' => $ad->cost_per_impression_ugx,
            'cost_per_click_ugx' => $ad->cost_per_click_ugx,
            'target_tiers' => $ad->target_tiers,
            'target_devices' => $ad->target_devices,
            'target_countries' => $ad->target_countries,
            'priority' => $ad->priority,
            'notes' => $ad->notes,
            'created_at' => $ad->created_at?->toISOString(),
            'updated_at' => $ad->updated_at?->toISOString(),
        ];
    }

    private function validationRules(bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        return [
            'title' => [$req, 'string', 'max:255'],
            'advertiser_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => [$req, Rule::in(self::AD_TYPES)],
            'format' => [$req, Rule::in(self::AD_FORMATS)],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'click_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'cta_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'html_content' => ['sometimes', 'nullable', 'string'],
            'audio_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'audio_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'native_headline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'native_body' => ['sometimes', 'nullable', 'string'],
            'native_image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'adsense_slot_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'adsense_format' => ['sometimes', 'nullable', Rule::in(['auto', 'rectangle', 'horizontal', 'vertical'])],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'total_budget_ugx' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'daily_budget_ugx' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_per_impression_ugx' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_per_click_ugx' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'target_tiers' => ['sometimes', 'nullable', 'array'],
            'target_tiers.*' => [Rule::in(self::VALID_TIERS)],
            'target_devices' => ['sometimes', 'nullable', 'array'],
            'target_devices.*' => [Rule::in(['desktop', 'mobile', 'tablet'])],
            'target_countries' => ['sometimes', 'nullable', 'array'],
            'target_countries.*' => ['string', 'size:2'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
