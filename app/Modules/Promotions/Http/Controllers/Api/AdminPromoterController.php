<?php

namespace App\Modules\Promotions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Models\PromotionOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPromoterController extends Controller
{
    // -------------------------------------------------------------------------
    // Promoter management
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status  = trim((string) $request->input('status', ''));
        $tier    = trim((string) $request->input('tier', ''));
        $search  = trim((string) $request->input('search', ''));
        $verified = $request->input('verified');

        $query = PromoterProfile::query()
            ->with(['user'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($tier !== '', fn ($q) => $q->where('tier', $tier))
            ->when($verified !== null, fn ($q) => $q->where('is_verified', filter_var($verified, FILTER_VALIDATE_BOOLEAN)))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('display_name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data'         => collect($query->items())->map(fn (PromoterProfile $p) => $this->serializeProfile($p))->values(),
            'current_page' => $query->currentPage(),
            'last_page'    => $query->lastPage(),
            'per_page'     => $query->perPage(),
            'total'        => $query->total(),
            'from'         => $query->firstItem(),
            'to'           => $query->lastItem(),
        ]);
    }

    public function verify(Request $request, int $id): JsonResponse
    {
        $profile = PromoterProfile::with('user')->findOrFail($id);

        $profile->forceFill([
            'is_verified' => true,
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => $this->serializeProfile($profile->fresh('user')),
        ]);
    }

    public function unverify(Request $request, int $id): JsonResponse
    {
        $profile = PromoterProfile::with('user')->findOrFail($id);

        $profile->forceFill([
            'is_verified' => false,
            'verified_at' => null,
            'verified_by' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => $this->serializeProfile($profile->fresh('user')),
        ]);
    }

    public function setTier(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'tier' => 'required|in:starter,rising,established,elite',
        ]);

        $profile = PromoterProfile::with('user')->findOrFail($id);
        $profile->forceFill(['tier' => $validated['tier']])->save();

        return response()->json([
            'success' => true,
            'data'    => $this->serializeProfile($profile->fresh('user')),
        ]);
    }

    // -------------------------------------------------------------------------
    // Opportunity oversight
    // -------------------------------------------------------------------------

    public function indexOpportunities(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $status  = trim((string) $request->input('status', ''));
        $search  = trim((string) $request->input('search', ''));

        $query = PromotionOpportunity::query()
            ->with(['creator', 'promotable'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhereHas('creator', fn ($u) => $u
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data'         => collect($query->items())->map(fn (PromotionOpportunity $opp) => $this->serializeOpportunity($opp))->values(),
            'current_page' => $query->currentPage(),
            'last_page'    => $query->lastPage(),
            'per_page'     => $query->perPage(),
            'total'        => $query->total(),
            'from'         => $query->firstItem(),
            'to'           => $query->lastItem(),
        ]);
    }

    public function forceClose(Request $request, string $uuid): JsonResponse
    {
        $opportunity = PromotionOpportunity::with(['creator', 'promotable'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        if (! $opportunity->canTransitionTo('closed')) {
            return response()->json([
                'message' => 'This opportunity cannot be closed in its current state (' . $opportunity->status . ').',
            ], 422);
        }

        $opportunity->transitionTo('closed');

        return response()->json([
            'success' => true,
            'data'    => $this->serializeOpportunity($opportunity->fresh(['creator', 'promotable'])),
        ]);
    }

    public function opportunityApplications(Request $request, string $uuid): JsonResponse
    {
        $perPage     = max(1, min((int) $request->integer('per_page', 20), 100));
        $opportunity = PromotionOpportunity::with(['creator', 'promotable'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $query = $opportunity->applications()
            ->with(['promoterProfile.user'])
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'opportunity'  => $this->serializeOpportunity($opportunity),
            'data'         => collect($query->items())->map(fn ($app) => [
                'id'                      => $app->id,
                'uuid'                    => $app->uuid,
                'status'                  => $app->status,
                'pitch_message'           => $app->pitch_message,
                'proposed_price_ugx'      => (float) ($app->proposed_price_ugx ?? 0),
                'proposed_price_credits'  => (int) ($app->proposed_price_credits ?? 0),
                'proposed_timeline_days'  => (int) ($app->proposed_timeline_days ?? 0),
                'artist_response'         => $app->artist_response,
                'reviewed_at'             => optional($app->reviewed_at)->toIso8601String(),
                'promoter'                => $app->promoterProfile ? $this->serializeProfile($app->promoterProfile) : null,
                'created_at'              => optional($app->created_at)->toIso8601String(),
            ])->values(),
            'current_page' => $query->currentPage(),
            'last_page'    => $query->lastPage(),
            'per_page'     => $query->perPage(),
            'total'        => $query->total(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Serializers
    // -------------------------------------------------------------------------

    private function serializeProfile(PromoterProfile $profile): array
    {
        $user = $profile->user;

        return [
            'id'                      => $profile->id,
            'slug'                    => $profile->slug,
            'display_name'            => $profile->display_name,
            'bio'                     => $profile->bio,
            'tier'                    => $profile->tier,
            'is_verified'             => (bool) $profile->is_verified,
            'verified_at'             => optional($profile->verified_at)->toIso8601String(),
            'status'                  => $profile->status,
            'platforms'               => $profile->platforms ?? [],
            'niches'                  => $profile->niches ?? [],
            'audience_regions'        => $profile->audience_regions ?? [],
            'total_listings'          => (int) ($profile->total_listings ?? 0),
            'total_completed_orders'  => (int) ($profile->total_completed_orders ?? 0),
            'average_rating'          => $profile->average_rating,
            'review_count'            => (int) ($profile->review_count ?? 0),
            'response_time_hours'     => (int) ($profile->response_time_hours ?? 24),
            'onboarded_at'            => optional($profile->onboarded_at)->toIso8601String(),
            'user'                    => $user ? [
                'id'         => $user->id,
                'name'       => $user->name,
                'username'   => $user->username,
                'email'      => $user->email,
                'avatar_url' => $user->avatar_url ?? $user->avatar ?? null,
            ] : null,
        ];
    }

    private function serializeOpportunity(PromotionOpportunity $opp): array
    {
        $creator   = $opp->creator;
        $promotable = $opp->promotable;

        return [
            'id'                     => $opp->id,
            'uuid'                   => $opp->uuid,
            'title'                  => $opp->title,
            'brief'                  => $opp->brief,
            'status'                 => $opp->status,
            'promotable_type'        => $opp->promotable_type,
            'promotable_id'          => $opp->promotable_id,
            'promotable'             => $promotable ? [
                'id'    => $promotable->id,
                'title' => $promotable->title ?? $promotable->name ?? null,
                'type'  => class_basename($opp->promotable_type),
            ] : null,
            'target_platforms'       => $opp->target_platforms ?? [],
            'target_audience_niches' => $opp->target_audience_niches ?? [],
            'target_regions'         => $opp->target_regions ?? [],
            'budget_min_ugx'         => (float) ($opp->budget_min_ugx ?? 0),
            'budget_max_ugx'         => (float) ($opp->budget_max_ugx ?? 0),
            'budget_credits'         => (int) ($opp->budget_credits ?? 0),
            'deadline_at'            => optional($opp->deadline_at)->toIso8601String(),
            'application_count'      => (int) ($opp->application_count ?? 0),
            'view_count'             => (int) ($opp->view_count ?? 0),
            'creator'                => $creator ? [
                'id'         => $creator->id,
                'name'       => $creator->name,
                'username'   => $creator->username,
                'avatar_url' => $creator->avatar_url ?? $creator->avatar ?? null,
            ] : null,
            'created_at'             => optional($opp->created_at)->toIso8601String(),
        ];
    }
}
