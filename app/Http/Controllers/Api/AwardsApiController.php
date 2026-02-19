<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AwardCategoryResource;
use App\Http\Resources\AwardNominationResource;
use App\Http\Resources\AwardResource;
use App\Models\Award;
use App\Models\AwardCategory;
use App\Models\AwardNomination;
use App\Models\AwardVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AwardsApiController extends Controller
{
    /**
     * GET /api/awards — list awards / seasons
     */
    public function index(Request $request)
    {
        $awards = Award::published()
            ->withCount(['categories', 'nominations'])
            ->latest('year')
            ->paginate($request->get('per_page', 15));

        return AwardResource::collection($awards);
    }

    /**
     * GET /api/awards/current-season — current award season
     */
    public function currentSeason()
    {
        $award = Award::currentSeason()
            ->published()
            ->withCount(['categories', 'nominations'])
            ->with(['categories' => fn ($q) => $q->active()->ordered()])
            ->first();

        if (! $award) {
            return response()->json(['message' => 'No active award season found.'], 404);
        }

        return new AwardResource($award);
    }

    /**
     * GET /api/awards/{id} — single award detail
     */
    public function show($id)
    {
        $award = Award::where('id', $id)
            ->orWhere('uuid', $id)
            ->orWhere('slug', $id)
            ->withCount(['categories', 'nominations'])
            ->with(['categories' => fn ($q) => $q->active()->ordered()->withCount('nominations')])
            ->firstOrFail();

        return new AwardResource($award);
    }

    /**
     * GET /api/awards/{id}/categories — award categories
     */
    public function categories($id)
    {
        $award = Award::where('id', $id)->orWhere('uuid', $id)->orWhere('slug', $id)->firstOrFail();

        $categories = AwardCategory::active()
            ->ordered()
            ->withCount(['nominations' => fn ($q) => $q->where('award_id', $award->id)->approved()])
            ->get();

        return AwardCategoryResource::collection($categories);
    }

    /**
     * GET /api/awards/{id}/categories/{categoryId}/nominations — nominations for a category
     */
    public function nominations(Request $request, $id, $categoryId)
    {
        $award = Award::where('id', $id)->orWhere('uuid', $id)->orWhere('slug', $id)->firstOrFail();

        $nominations = AwardNomination::where('award_id', $award->id)
            ->where('category_id', $categoryId)
            ->approved()
            ->with('nominatedBy:id,username')
            ->paginate($request->get('per_page', 20));

        return AwardNominationResource::collection($nominations);
    }

    /**
     * POST /api/awards/{id}/nominations — submit a nomination (auth required)
     */
    public function submitNomination(Request $request, $id): JsonResponse
    {
        $award = Award::where('id', $id)->orWhere('uuid', $id)->orWhere('slug', $id)->firstOrFail();

        if (! $award->isNominationOpen()) {
            return response()->json(['message' => 'Nominations are not currently open for this award.'], 422);
        }

        if (! $award->allow_public_nominations) {
            return response()->json(['message' => 'Public nominations are not allowed for this award.'], 403);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:award_categories,id',
            'nominee_name' => 'required|string|max:255',
            'nominee_type' => 'nullable|string|max:255',
            'nominee_id' => 'nullable|integer',
            'nominee_artwork' => 'nullable|string|max:500',
            'nomination_reason' => 'nullable|string|max:1000',
        ]);

        // Check for duplicate nomination by this user in this category
        $existing = AwardNomination::where('award_id', $award->id)
            ->where('category_id', $validated['category_id'])
            ->where('nominated_by_id', $request->user()->id)
            ->where('nominee_name', $validated['nominee_name'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already nominated this entry in this category.'], 422);
        }

        $nomination = AwardNomination::create([
            'award_id' => $award->id,
            'category_id' => $validated['category_id'],
            'nominee_name' => $validated['nominee_name'],
            'nominee_type' => $validated['nominee_type'] ?? null,
            'nominee_id' => $validated['nominee_id'] ?? null,
            'nominee_artwork' => $validated['nominee_artwork'] ?? null,
            'nomination_reason' => $validated['nomination_reason'] ?? null,
            'nominated_by_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => new AwardNominationResource($nomination),
            'message' => 'Nomination submitted successfully.',
        ], 201);
    }

    /**
     * POST /api/awards/{id}/vote — cast a vote (auth required)
     */
    public function vote(Request $request, $id): JsonResponse
    {
        $award = Award::where('id', $id)->orWhere('uuid', $id)->orWhere('slug', $id)->firstOrFail();

        if (! $award->isVotingOpen()) {
            return response()->json(['message' => 'Voting is not currently open for this award.'], 422);
        }

        if (! $award->allow_public_voting) {
            return response()->json(['message' => 'Public voting is not allowed for this award.'], 403);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:award_categories,id',
            'nomination_id' => 'required|integer|exists:award_nominations,id',
        ]);

        // Verify nomination belongs to this award and category
        $nomination = AwardNomination::where('id', $validated['nomination_id'])
            ->where('award_id', $award->id)
            ->where('category_id', $validated['category_id'])
            ->approved()
            ->firstOrFail();

        // Check vote limit per category
        $existingVotes = AwardVote::where('award_id', $award->id)
            ->where('category_id', $validated['category_id'])
            ->where('user_id', $request->user()->id)
            ->count();

        if ($existingVotes >= $award->votes_per_category) {
            return response()->json([
                'message' => "You have already used all {$award->votes_per_category} vote(s) in this category.",
            ], 422);
        }

        $vote = AwardVote::create([
            'award_id' => $award->id,
            'category_id' => $validated['category_id'],
            'nomination_id' => $validated['nomination_id'],
            'user_id' => $request->user()->id,
            'weight' => 1,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => ['voted' => true],
            'message' => 'Vote cast successfully.',
        ], 201);
    }

    /**
     * GET /api/awards/{id}/results — voting results
     */
    public function results($id)
    {
        $award = Award::where('id', $id)->orWhere('uuid', $id)->orWhere('slug', $id)->firstOrFail();

        // Only show results if voting is closed or completed
        if (! in_array($award->status, [Award::STATUS_VOTING_CLOSED, Award::STATUS_COMPLETED])) {
            return response()->json(['message' => 'Results are not yet available.'], 403);
        }

        $categories = AwardCategory::active()
            ->ordered()
            ->get()
            ->map(function ($category) use ($award) {
                $nominations = AwardNomination::where('award_id', $award->id)
                    ->where('category_id', $category->id)
                    ->approved()
                    ->withCount('votes')
                    ->orderByDesc('votes_count')
                    ->get();

                return [
                    'category' => new AwardCategoryResource($category),
                    'nominations' => AwardNominationResource::collection($nominations),
                    'total_votes' => $nominations->sum('votes_count'),
                ];
            });

        return response()->json([
            'data' => [
                'award' => new AwardResource($award),
                'results' => $categories,
            ],
        ]);
    }
}
