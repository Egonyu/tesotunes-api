<?php

namespace App\Modules\Promotions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Event;
use App\Models\Song;
use App\Modules\Promotions\Models\PromotionApplication;
use App\Modules\Promotions\Models\PromotionOpportunity;
use App\Modules\Promotions\Services\OpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    public function __construct(private readonly OpportunityService $opportunityService) {}

    /**
     * Browse open opportunities — public feed for influencers to discover work.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PromotionOpportunity::with(['creator:id,username,avatar', 'promotable'])
            ->open()
            ->orderByDesc('created_at');

        if ($request->filled('platform')) {
            $query->whereJsonContains('target_platforms', $request->string('platform')->toString());
        }

        if ($request->filled('niche')) {
            $query->whereJsonContains('target_audience_niches', $request->string('niche')->toString());
        }

        if ($request->filled('region')) {
            $query->whereJsonContains('target_regions', $request->string('region')->toString());
        }

        if ($request->filled('promotable_type')) {
            $morphMap = ['song' => Song::class, 'album' => Album::class, 'event' => Event::class];
            $type = $morphMap[$request->string('promotable_type')->toString()] ?? null;

            if ($type) {
                $query->where('promotable_type', $type);
            }
        }

        // Personalized feed for logged-in promoters
        if ($request->user()?->promoterProfile) {
            $query->forPromoter($request->user()->promoterProfile);
        }

        $opportunities = $query->paginate($request->integer('per_page', 20));

        return response()->json($opportunities);
    }

    /**
     * View a single opportunity (increments view count).
     */
    public function show(string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::with(['creator:id,username,avatar', 'promotable', 'applications'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->authorize('view', $opportunity);
        $opportunity->incrementViewCount();

        return response()->json(['data' => $opportunity]);
    }

    /**
     * Create a new opportunity for a song or album.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PromotionOpportunity::class);

        $data = $request->validate([
            'promotable_type' => ['required', Rule::in(['song', 'album', 'event'])],
            'promotable_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'brief' => 'nullable|string|max:5000',
            'target_platforms' => 'nullable|array',
            'target_platforms.*' => 'string|max:50',
            'target_audience_niches' => 'nullable|array',
            'target_audience_niches.*' => 'string|max:50',
            'target_regions' => 'nullable|array',
            'target_regions.*' => 'string|max:100',
            'budget_min_ugx' => 'nullable|numeric|min:0',
            'budget_max_ugx' => 'nullable|numeric|min:0',
            'budget_credits' => 'nullable|integer|min:0',
            'max_awards' => 'nullable|integer|min:1|max:20',
            'deadline_at' => 'nullable|date|after:today',
            'deliverables' => 'nullable|array',
        ]);

        $morphMap = ['song' => Song::class, 'album' => Album::class, 'event' => Event::class];
        $modelClass = $morphMap[$data['promotable_type']];
        $promotable = $modelClass::findOrFail($data['promotable_id']);

        // Ownership check — only the content owner can post an opportunity for it
        $ownerId = $data['promotable_type'] === 'event'
            ? ($promotable->organizer_id ?? $promotable->user_id ?? $promotable->artist?->user_id)
            : ($promotable->user_id ?? $promotable->artist?->user_id);
        if ($ownerId !== $request->user()->id && ! in_array($request->user()->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'You do not own this content.'], 403);
        }

        try {
            $opportunity = $this->opportunityService->createForContent($request->user(), $promotable, $data);

            return response()->json(['data' => $opportunity], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to post opportunity.'], 500);
        }
    }

    /**
     * Update an open opportunity (owner only, before applications are awarded).
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $opportunity);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'brief' => 'nullable|string|max:5000',
            'target_platforms' => 'nullable|array',
            'target_audience_niches' => 'nullable|array',
            'target_regions' => 'nullable|array',
            'budget_min_ugx' => 'nullable|numeric|min:0',
            'budget_max_ugx' => 'nullable|numeric|min:0',
            'budget_credits' => 'nullable|integer|min:0',
            'deadline_at' => 'nullable|date|after:today',
            'deliverables' => 'nullable|array',
        ]);

        $opportunity->update($data);

        return response()->json(['data' => $opportunity->fresh()]);
    }

    /**
     * Close/cancel an opportunity (owner only).
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();

        $this->authorize('delete', $opportunity);
        $opportunity->transitionTo(PromotionOpportunity::STATUS_CANCELLED);

        return response()->json(['message' => 'Opportunity cancelled.']);
    }

    /**
     * Manually close an opportunity (marks it closed, not cancelled).
     */
    public function close(Request $request, string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();

        $this->authorize('manageApplications', $opportunity);
        $opportunity->transitionTo(PromotionOpportunity::STATUS_CLOSED);

        return response()->json(['message' => 'Opportunity closed.']);
    }

    /**
     * Apply to an opportunity as a promoter.
     */
    public function apply(Request $request, string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();

        $this->authorize('apply', $opportunity);

        $data = $request->validate([
            'proposed_price_ugx' => 'nullable|numeric|min:0',
            'proposed_price_credits' => 'nullable|integer|min:0',
            'pitch_message' => 'nullable|string|max:2000',
            'proposed_deliverables' => 'nullable|array',
            'proposed_timeline_days' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $application = $this->opportunityService->apply($opportunity, $request->user(), $data);

            return response()->json(['data' => $application], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * List applications for an opportunity (opportunity owner only).
     */
    public function applications(Request $request, string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();

        $this->authorize('manageApplications', $opportunity);

        $applications = $opportunity->applications()
            ->with('promoterProfile:id,slug,display_name,tier,is_verified,average_rating,total_completed_orders', 'applicant:id,username,avatar')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($applications);
    }

    /**
     * Award an opportunity to a specific applicant.
     */
    public function award(Request $request, string $uuid, int $applicationId): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();
        $application = PromotionApplication::findOrFail($applicationId);

        $this->authorize('manageApplications', $opportunity);

        $payment = $request->validate([
            'payment_method' => ['required', Rule::in(['ugx', 'credits'])],
        ]);

        try {
            $this->opportunityService->award($opportunity, $application, $payment);

            return response()->json([
                'message' => 'Application awarded and funded. The promoter can start work.',
                'data' => [
                    'order_id' => $application->fresh()->order_id,
                    'slots_remaining' => max(0, (int) $opportunity->fresh()->max_awards - (int) $opportunity->fresh()->awarded_count),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Shortlist an application.
     */
    public function shortlist(Request $request, string $uuid, int $applicationId): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();
        $application = PromotionApplication::findOrFail($applicationId);

        $this->authorize('manageApplications', $opportunity);
        $this->opportunityService->shortlist($application);

        return response()->json(['message' => 'Application shortlisted.']);
    }

    /**
     * Withdraw your own application.
     */
    public function withdrawApplication(Request $request, string $uuid, int $applicationId): JsonResponse
    {
        $opportunity = PromotionOpportunity::where('uuid', $uuid)->firstOrFail();
        $application = PromotionApplication::findOrFail($applicationId);

        try {
            $this->opportunityService->withdrawApplication($application, $request->user());

            return response()->json(['message' => 'Application withdrawn.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Opportunities posted by the authenticated user.
     */
    public function myPosted(Request $request): JsonResponse
    {
        $opportunities = PromotionOpportunity::with('promotable')
            ->where('created_by_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($opportunities);
    }

    /**
     * Applications submitted by the authenticated promoter.
     */
    public function myApplications(Request $request): JsonResponse
    {
        $profile = $request->user()->promoterProfile;

        if (! $profile) {
            return response()->json(['data' => [], 'message' => 'No promoter profile found.']);
        }

        $applications = PromotionApplication::with('opportunity.promotable')
            ->where('promoter_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($applications);
    }
}
