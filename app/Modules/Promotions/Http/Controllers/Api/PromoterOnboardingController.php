<?php

namespace App\Modules\Promotions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Services\PromoterOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoterOnboardingController extends Controller
{
    public function __construct(private readonly PromoterOnboardingService $onboardingService) {}

    /**
     * Browse the promoter marketplace — public, unauthenticated.
     */
    public function discover(Request $request): JsonResponse
    {
        $query = PromoterProfile::with('user:id,username,avatar')
            ->active()
            ->orderByDesc('average_rating')
            ->orderByDesc('total_completed_orders');

        if ($request->filled('tier')) {
            $query->byTier($request->string('tier'));
        }

        if ($request->filled('platform')) {
            $query->whereJsonContains('platforms', $request->string('platform')->toString());
        }

        if ($request->filled('niche')) {
            $query->whereJsonContains('niches', $request->string('niche')->toString());
        }

        if ($request->filled('region')) {
            $query->whereJsonContains('audience_regions', $request->string('region')->toString());
        }

        $profiles = $query->paginate($request->integer('per_page', 20));

        return response()->json($profiles);
    }

    /**
     * View a single promoter profile — public.
     */
    public function show(string $slug): JsonResponse
    {
        $profile = PromoterProfile::with('user:id,username,name,avatar')
            ->where('slug', $slug)
            ->firstOrFail();

        $this->authorize('view', $profile);

        return response()->json(['data' => $profile]);
    }

    /**
     * Onboard the authenticated user as a promoter.
     * No artist role required — any user can onboard.
     */
    public function onboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => 'sometimes|string|max:200',
            'bio' => 'nullable|string|max:2000',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:50',
            'niches' => 'nullable|array',
            'niches.*' => 'string|max:50',
            'audience_regions' => 'nullable|array',
            'audience_regions.*' => 'string|max:100',
            'audience_summary' => 'nullable|string|max:500',
            'social_links' => 'nullable|array',
            'response_time_hours' => 'nullable|integer|min:1|max:168',
        ]);

        try {
            $profile = $this->onboardingService->onboard($request->user(), $data);

            $alreadyExisted = $profile->wasRecentlyCreated === false;

            return response()->json([
                'data' => $profile,
                'message' => $alreadyExisted ? 'You are already a promoter.' : 'Welcome! Your promoter profile is ready.',
            ], $alreadyExisted ? 200 : 201);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Onboarding failed. Please try again.'], 500);
        }
    }

    /**
     * Get the authenticated user's promoter profile.
     */
    public function myProfile(Request $request): JsonResponse
    {
        $profile = PromoterProfile::where('user_id', $request->user()->id)->first();

        if (! $profile) {
            return response()->json(['data' => null, 'is_promoter' => false]);
        }

        return response()->json(['data' => $profile, 'is_promoter' => true]);
    }

    /**
     * Update the authenticated user's promoter profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $profile = PromoterProfile::where('user_id', $request->user()->id)->firstOrFail();

        $this->authorize('update', $profile);

        $data = $request->validate([
            'display_name' => 'sometimes|string|max:200',
            'bio' => 'nullable|string|max:2000',
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:50',
            'niches' => 'nullable|array',
            'niches.*' => 'string|max:50',
            'audience_regions' => 'nullable|array',
            'audience_regions.*' => 'string|max:100',
            'audience_summary' => 'nullable|string|max:500',
            'social_links' => 'nullable|array',
            'portfolio_items' => 'nullable|array',
            'proof_points' => 'nullable|array',
            'campaign_highlights' => 'nullable|array',
            'response_time_hours' => 'nullable|integer|min:1|max:168',
        ]);

        $updated = $this->onboardingService->updateProfile($profile, $data);

        return response()->json(['data' => $updated]);
    }
}
