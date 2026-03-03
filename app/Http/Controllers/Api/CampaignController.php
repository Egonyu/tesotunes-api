<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\CampaignUpdateResource;
use App\Http\Resources\PledgeResource;
use App\Models\Campaign;
use App\Models\CampaignPledge;
use App\Models\CampaignUpdate;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public-facing Ojokotau (crowdfunding) API.
 *
 * Allows users to browse campaigns, create campaigns, make pledges,
 * and post campaign updates.
 */
class CampaignController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/campaigns
     *
     * Browse active/featured campaigns (public — no auth required).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $query = Campaign::with('user:id,name,username,avatar')
                ->withCount('pledges', 'updates')
                ->withSum('pledges', 'amount');

            // Search
            $query->search($request->get('search'));

            // Filter by status (public can only see active + completed)
            $status = $request->get('status', 'active');
            if ($status === 'all') {
                $query->whereIn('status', ['active', 'completed', 'closed']);
            } else {
                $query->where('status', $status);
            }

            // Filter by category
            if ($category = $request->get('category')) {
                $query->where('category', $category);
            }

            // Filter by urgency
            if ($urgency = $request->get('urgency')) {
                $query->where('urgency', $urgency);
            }

            // Featured only
            if ($request->boolean('featured')) {
                $query->featured();
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDir = $request->get('sort_dir', 'desc');
            $allowedSorts = ['created_at', 'target_amount', 'view_count', 'share_count', 'end_date'];
            if (in_array($sortBy, $allowedSorts, true)) {
                $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
            }

            $perPage = min((int) $request->get('per_page', 12), 50);
            $campaigns = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => CampaignResource::collection($campaigns)->resolve(),
                'meta' => [
                    'total' => $campaigns->total(),
                    'per_page' => $campaigns->perPage(),
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                ],
            ]);
        }, 'Failed to retrieve campaigns.');
    }

    /**
     * GET /api/campaigns/featured
     *
     * Get featured campaigns for homepage showcase.
     */
    public function featured(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $campaigns = Campaign::with('user:id,name,username,avatar')
                ->withCount('pledges')
                ->withSum('pledges', 'amount')
                ->featured()
                ->active()
                ->orderByDesc('featured_at')
                ->limit(6)
                ->get();

            return response()->json([
                'success' => true,
                'data' => CampaignResource::collection($campaigns)->resolve(),
            ]);
        }, 'Failed to retrieve featured campaigns.');
    }

    /**
     * GET /api/campaigns/categories
     *
     * Get available campaign categories with counts.
     */
    public function categories(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $categories = Campaign::whereIn('status', ['active', 'completed', 'closed'])
                ->selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->orderByDesc('count')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        }, 'Failed to retrieve campaign categories.');
    }

    /**
     * GET /api/campaigns/{slug}
     *
     * View a single campaign detail (public).
     */
    public function show(string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->with([
                    'user:id,name,username,avatar',
                    'beneficiaryArtist:id,name,stage_name',
                ])
                ->withCount('pledges', 'updates')
                ->withSum('pledges', 'amount')
                ->firstOrFail();

            // Increment view count (async)
            \App\Jobs\IncrementCounter::dispatch('campaigns', $campaign->id, 'view_count');

            return response()->json([
                'success' => true,
                'data' => new CampaignResource($campaign),
            ]);
        }, 'Failed to retrieve campaign.');
    }

    /**
     * POST /api/campaigns
     *
     * Create a new campaign (authenticated users — submitted for review).
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:2000',
                'story' => 'nullable|string|max:10000',
                'category' => 'required|string|max:50',
                'urgency' => 'nullable|in:low,medium,high,critical',
                'beneficiary_type' => 'required|in:self,artist,community,other',
                'beneficiary_name' => 'required|string|max:255',
                'beneficiary_relationship' => 'nullable|string|max:100',
                'beneficiary_artist_id' => 'nullable|integer|exists:artists,id',
                'momo_network' => 'nullable|in:mtn,airtel',
                'momo_number' => 'nullable|string|max:20',
                'momo_name' => 'nullable|string|max:255',
                'contact_name' => 'required|string|max:255',
                'contact_phone' => 'required|string|max:20',
                'contact_role' => 'nullable|string|max:100',
                'target_amount' => 'required|numeric|min:1000|max:100000000',
                'end_date' => 'required|date|after:today',
                'terms_accepted' => 'required|accepted',
            ]);

            $validated['user_id'] = $request->user()->id;
            $validated['status'] = 'pending';
            $validated['submitted_at'] = now();
            $validated['terms_accepted_at'] = now();

            $campaign = Campaign::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Campaign submitted for review.',
                'data' => new CampaignResource($campaign->load('user:id,name,username,avatar')),
            ], 201);
        }, 'Failed to create campaign.');
    }

    /**
     * PUT /api/campaigns/{slug}
     *
     * Update own campaign (only if pending or revision_requested).
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->firstOrFail();

            // Ownership check
            if ($campaign->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only edit your own campaigns.',
                ], 403);
            }

            // Status check — can only edit pending or revision_requested campaigns
            if (! in_array($campaign->status, ['pending', 'revision_requested', 'draft'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This campaign can no longer be edited.',
                ], 422);
            }

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string|max:2000',
                'story' => 'nullable|string|max:10000',
                'category' => 'sometimes|string|max:50',
                'urgency' => 'nullable|in:low,medium,high,critical',
                'beneficiary_name' => 'sometimes|string|max:255',
                'beneficiary_relationship' => 'nullable|string|max:100',
                'momo_network' => 'nullable|in:mtn,airtel',
                'momo_number' => 'nullable|string|max:20',
                'momo_name' => 'nullable|string|max:255',
                'contact_name' => 'sometimes|string|max:255',
                'contact_phone' => 'sometimes|string|max:20',
                'target_amount' => 'sometimes|numeric|min:1000|max:100000000',
                'end_date' => 'sometimes|date|after:today',
            ]);

            // If previously revision_requested, resubmit
            if ($campaign->status === 'revision_requested') {
                $validated['status'] = 'pending';
                $validated['submitted_at'] = now();
            }

            $campaign->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Campaign updated successfully.',
                'data' => new CampaignResource($campaign->fresh(['user:id,name,username,avatar'])),
            ]);
        }, 'Failed to update campaign.');
    }

    /**
     * GET /api/campaigns/{slug}/pledges
     *
     * View pledges for a campaign (public, respects anonymity).
     */
    public function pledges(Request $request, string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->firstOrFail();

            $query = $campaign->pledges()
                ->with('user:id,name,username,avatar')
                ->orderByDesc('created_at');

            $perPage = min((int) $request->get('per_page', 20), 50);
            $pledges = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => PledgeResource::collection($pledges)->resolve(),
                'meta' => [
                    'total' => $pledges->total(),
                    'per_page' => $pledges->perPage(),
                    'current_page' => $pledges->currentPage(),
                    'last_page' => $pledges->lastPage(),
                    'total_raised' => (float) $campaign->pledges()->sum('amount'),
                ],
            ]);
        }, 'Failed to retrieve campaign pledges.');
    }

    /**
     * POST /api/campaigns/{slug}/pledge
     *
     * Make a pledge to a campaign (authenticated).
     */
    public function pledge(Request $request, string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->firstOrFail();

            if ($campaign->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'This campaign is not currently accepting pledges.',
                ], 422);
            }

            if ($campaign->end_date && $campaign->end_date->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This campaign has ended.',
                ], 422);
            }

            $validated = $request->validate([
                'amount' => 'required|numeric|min:500|max:50000000',
                'message' => 'nullable|string|max:500',
                'is_anonymous' => 'boolean',
            ]);

            $validated['campaign_id'] = $campaign->id;
            $validated['user_id'] = $request->user()->id;

            $pledge = CampaignPledge::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Pledge submitted successfully. Thank you for your support!',
                'data' => new PledgeResource($pledge->load('user:id,name,username,avatar')),
            ], 201);
        }, 'Failed to submit pledge.');
    }

    /**
     * GET /api/campaigns/{slug}/updates
     *
     * View public updates for a campaign.
     */
    public function updates(Request $request, string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->firstOrFail();

            $query = $campaign->updates()
                ->with('user:id,name,username,avatar')
                ->where('is_public', true)
                ->orderByDesc('created_at');

            $perPage = min((int) $request->get('per_page', 10), 50);
            $updates = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => CampaignUpdateResource::collection($updates)->resolve(),
                'meta' => [
                    'total' => $updates->total(),
                    'per_page' => $updates->perPage(),
                    'current_page' => $updates->currentPage(),
                    'last_page' => $updates->lastPage(),
                ],
            ]);
        }, 'Failed to retrieve campaign updates.');
    }

    /**
     * POST /api/campaigns/{slug}/updates
     *
     * Post an update to own campaign (campaign owner only).
     */
    public function addUpdate(Request $request, string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->firstOrFail();

            // Ownership check
            if ($campaign->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only post updates to your own campaigns.',
                ], 403);
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string|max:5000',
                'type' => 'nullable|in:update,milestone,thank_you,completion',
                'is_public' => 'boolean',
            ]);

            $validated['campaign_id'] = $campaign->id;
            $validated['user_id'] = $request->user()->id;
            $validated['is_public'] = $validated['is_public'] ?? true;
            $validated['type'] = $validated['type'] ?? 'update';

            $update = CampaignUpdate::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Campaign update posted.',
                'data' => new CampaignUpdateResource($update->load('user:id,name,username,avatar')),
            ], 201);
        }, 'Failed to post campaign update.');
    }

    /**
     * GET /api/campaigns/my
     *
     * Get campaigns created by the authenticated user.
     */
    public function myCampaigns(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $query = Campaign::where('user_id', $request->user()->id)
                ->withCount('pledges', 'updates')
                ->withSum('pledges', 'amount')
                ->orderByDesc('created_at');

            if ($status = $request->get('status')) {
                $query->where('status', $status);
            }

            $perPage = min((int) $request->get('per_page', 10), 50);
            $campaigns = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => CampaignResource::collection($campaigns)->resolve(),
                'meta' => [
                    'total' => $campaigns->total(),
                    'per_page' => $campaigns->perPage(),
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                ],
            ]);
        }, 'Failed to retrieve your campaigns.');
    }

    /**
     * POST /api/campaigns/{slug}/share
     *
     * Track a campaign share (authenticated).
     */
    public function share(string $slug): JsonResponse
    {
        return $this->handleApiAction(function () use ($slug) {
            $campaign = Campaign::where('slug', $slug)
                ->orWhere('uuid', $slug)
                ->firstOrFail();

            \App\Jobs\IncrementCounter::dispatch('campaigns', $campaign->id, 'share_count');

            return response()->json([
                'success' => true,
                'message' => 'Share recorded.',
            ]);
        }, 'Failed to record share.');
    }
}
