<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\CampaignUpdateResource;
use App\Http\Resources\PledgeResource;
use App\Models\Campaign;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\Request;

class CampaignsApiController extends Controller
{
    use HandlesApiErrors;
    /**
     * GET /api/admin/campaigns/stats
     */
    public function stats()
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_campaigns' => Campaign::count(),
                    'active_campaigns' => Campaign::active()->count(),
                    'pending_approval' => Campaign::pending()->count(),
                    'total_raised' => (float) Campaign::join('campaign_pledges', 'campaigns.id', '=', 'campaign_pledges.campaign_id')
                        ->sum('campaign_pledges.amount'),
                    'total_pledges' => \App\Models\CampaignPledge::count(),
                    'recent_pledges_30d' => (float) \App\Models\CampaignPledge::where('created_at', '>=', now()->subDays(30))
                        ->sum('amount'),
                ],
            ]);
        }, 'Failed to retrieve campaign stats.');
    }

    /**
     * GET /api/admin/campaigns
     */
    public function index(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $campaigns = Campaign::query()
                ->with('user')
                ->withCount('pledges', 'updates')
                ->withSum('pledges', 'amount')
                ->search($request->get('search'))
                ->when($request->get('status') && $request->get('status') !== 'all', function ($q) use ($request) {
                    $q->where('status', $request->get('status'));
                })
                ->when($request->get('category') && $request->get('category') !== 'all', function ($q) use ($request) {
                    $q->where('category', $request->get('category'));
                })
                ->latest()
                ->paginate($this->getPerPage($request, 10));

            return response()->json([
                'success' => true,
                'data' => CampaignResource::collection($campaigns),
                'meta' => [
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                    'per_page' => $campaigns->perPage(),
                    'total' => $campaigns->total(),
                ],
            ]);
        }, 'Failed to retrieve campaigns.');
    }

    /**
     * GET /api/admin/campaigns/{id}
     */
    public function show($id)
    {
        return $this->handleApiAction(function () use ($id) {
            $campaign = Campaign::with('user')
                ->withCount('pledges', 'updates')
                ->withSum('pledges', 'amount')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new CampaignResource($campaign),
            ]);
        }, 'Failed to retrieve campaign.');
    }

    /**
     * POST /api/admin/campaigns
     */
    public function store(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'story' => 'nullable|string',
                'category' => 'required|string|max:50',
                'beneficiary_name' => 'required|string|max:255',
                'beneficiary_type' => 'required|string|max:30',
                'beneficiary_relationship' => 'nullable|string|max:100',
                'urgency' => 'nullable|in:low,medium,high,critical',
                'status' => 'nullable|in:draft,pending,active,completed,cancelled',
                'target_amount' => 'nullable|numeric|min:0',
                'end_date' => 'nullable|date|after:today',
                'contact_name' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:20',
                'contact_role' => 'nullable|string|max:100',
            ]);

            $validated['user_id'] = $request->user()->id;
            $validated['status'] = $validated['status'] ?? 'draft';
            $validated['urgency'] = $validated['urgency'] ?? 'medium';

            $campaign = Campaign::create($validated);

            $campaign->load('user');
            $campaign->loadCount('pledges', 'updates');

            return response()->json([
                'success' => true,
                'message' => 'Campaign created successfully.',
                'data' => new CampaignResource($campaign),
            ], 201);
        }, 'Failed to create campaign.');
    }

    /**
     * PUT /api/admin/campaigns/{id}
     */
    public function update(Request $request, $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $campaign = Campaign::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'story' => 'nullable|string',
                'category' => 'sometimes|string|max:50',
                'beneficiary_name' => 'sometimes|string|max:255',
                'urgency' => 'nullable|in:low,medium,high,critical',
                'status' => 'nullable|in:draft,pending,active,completed,cancelled',
                'target_amount' => 'nullable|numeric|min:0',
                'end_date' => 'nullable|date',
                'contact_name' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:20',
            ]);

            // Re-slug if title changed
            if (isset($validated['title'])) {
                $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']).'-'.\Illuminate\Support\Str::random(8);
            }

            $campaign->update($validated);

            $campaign->load('user');
            $campaign->loadCount('pledges', 'updates');
            $campaign->loadSum('pledges', 'amount');

            return response()->json([
                'success' => true,
                'message' => 'Campaign updated successfully.',
                'data' => new CampaignResource($campaign),
            ]);
        }, 'Failed to update campaign.');
    }

    /**
     * DELETE /api/admin/campaigns/{id}
     */
    public function destroy($id)
    {
        return $this->handleApiAction(function () use ($id) {
            $campaign = Campaign::findOrFail($id);
            $campaign->delete();

            return response()->json(['success' => true, 'message' => 'Campaign deleted successfully.']);
        }, 'Failed to delete campaign.');
    }

    /**
     * POST /api/admin/campaigns/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $campaign = Campaign::findOrFail($id);

            $campaign->update([
                'status' => 'active',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'activated_at' => now(),
            ]);

            $campaign->load('user');
            $campaign->loadCount('pledges', 'updates');
            $campaign->loadSum('pledges', 'amount');

            return response()->json([
                'success' => true,
                'message' => 'Campaign approved successfully.',
                'data' => new CampaignResource($campaign),
            ]);
        }, 'Failed to approve campaign.');
    }

    /**
     * POST /api/admin/campaigns/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $campaign = Campaign::findOrFail($id);

            $validated = $request->validate([
                'reason' => 'required|string',
            ]);

            $campaign->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $request->user()->id,
                'rejection_reason' => $validated['reason'],
            ]);

            $campaign->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Campaign rejected.',
                'data' => new CampaignResource($campaign),
            ]);
        }, 'Failed to reject campaign.');
    }

    /**
     * GET /api/admin/campaigns/{id}/pledges
     */
    public function pledges(Request $request, $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $campaign = Campaign::findOrFail($id);

            $pledges = $campaign->pledges()
                ->with('user')
                ->latest()
                ->paginate($this->getPerPage($request));

            return response()->json([
                'success' => true,
                'data' => PledgeResource::collection($pledges),
                'meta' => [
                    'current_page' => $pledges->currentPage(),
                    'last_page' => $pledges->lastPage(),
                    'per_page' => $pledges->perPage(),
                    'total' => $pledges->total(),
                ],
            ]);
        }, 'Failed to retrieve campaign pledges.');
    }

    /**
     * GET /api/admin/campaigns/{id}/updates
     */
    public function updates(Request $request, $id)
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $campaign = Campaign::findOrFail($id);

            $updates = $campaign->updates()
                ->with('user')
                ->latest()
                ->paginate($this->getPerPage($request));

            return response()->json([
                'success' => true,
                'data' => CampaignUpdateResource::collection($updates),
                'meta' => [
                    'current_page' => $updates->currentPage(),
                    'last_page' => $updates->lastPage(),
                    'per_page' => $updates->perPage(),
                    'total' => $updates->total(),
                ],
            ]);
        }, 'Failed to retrieve campaign updates.');
    }
}
