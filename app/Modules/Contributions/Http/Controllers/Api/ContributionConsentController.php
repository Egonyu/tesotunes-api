<?php

namespace App\Modules\Contributions\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Contributions\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data-terms consent for the corpus pipeline. A contributor must accept the
 * current terms once before any task surface (9.2+) lets them submit.
 */
class ContributionConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consent) {}

    /**
     * GET /api/contributions/consent — current consent state for the user.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->consent->profileFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'needs_consent' => $this->consent->needsConsent($user),
                'terms_version' => $this->consent->currentTermsVersion(),
                'license_version' => config('contributions.license_version'),
                'consented_at' => $profile?->consented_at?->toIso8601String(),
                'consented_version' => $profile?->consent_terms_version,
            ],
        ]);
    }

    /**
     * POST /api/contributions/consent — accept the current data terms.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'accept' => ['required', 'accepted'],
        ], [
            'accept.accepted' => 'You must accept the contribution data terms to continue.',
        ]);

        $profile = $this->consent->recordConsent($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Thank you — your contributions help build the Ateso corpus.',
            'data' => [
                'consented_at' => $profile->consented_at?->toIso8601String(),
                'terms_version' => $profile->consent_terms_version,
                'tier' => $profile->tier,
            ],
        ], 201);
    }
}
