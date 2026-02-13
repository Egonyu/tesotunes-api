<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\CampaignUpdateResource;
use App\Http\Resources\PledgeResource;
use App\Models\Campaign;
use Illuminate\Http\Request;

class CampaignsApiController extends Controller
{
    /**
     * GET /api/admin/campaigns/stats
     */
    public function stats()
    {
        return response()->json([
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
    }

    /**
     * GET /api/admin/campaigns
     */
    public function index(Request $request)
    {
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
            ->paginate($request->get('per_page', 10));

        return CampaignResource::collection($campaigns);
    }

    /**
     * GET /api/admin/campaigns/{id}
     */
    public function show($id)
    {
        $campaign = Campaign::with('user')
            ->withCount('pledges', 'updates')
            ->withSum('pledges', 'amount')
            ->findOrFail($id);

        return new CampaignResource($campaign);
    }

    /**
     * POST /api/admin/campaigns
     */
    public function store(Request $request)
    {
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

        return (new CampaignResource($campaign))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT /api/admin/campaigns/{id}
     */
    public function update(Request $request, $id)
    {
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
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']) . '-' . \Illuminate\Support\Str::random(8);
        }

        $campaign->update($validated);

        $campaign->load('user');
        $campaign->loadCount('pledges', 'updates');
        $campaign->loadSum('pledges', 'amount');

        return new CampaignResource($campaign);
    }

    /**
     * DELETE /api/admin/campaigns/{id}
     */
    public function destroy($id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted successfully.']);
    }

    /**
     * POST /api/admin/campaigns/{id}/approve
     */
    public function approve(Request $request, $id)
    {
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

        return new CampaignResource($campaign);
    }

    /**
     * POST /api/admin/campaigns/{id}/reject
     */
    public function reject(Request $request, $id)
    {
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

        return new CampaignResource($campaign);
    }

    /**
     * GET /api/admin/campaigns/{id}/pledges
     */
    public function pledges(Request $request, $id)
    {
        $campaign = Campaign::findOrFail($id);

        $pledges = $campaign->pledges()
            ->with('user')
            ->latest()
            ->paginate($request->get('per_page', 20));

        return PledgeResource::collection($pledges);
    }

    /**
     * GET /api/admin/campaigns/{id}/updates
     */
    public function updates(Request $request, $id)
    {
        $campaign = Campaign::findOrFail($id);

        $updates = $campaign->updates()
            ->with('user')
            ->latest()
            ->paginate($request->get('per_page', 20));

        return CampaignUpdateResource::collection($updates);
    }
}
