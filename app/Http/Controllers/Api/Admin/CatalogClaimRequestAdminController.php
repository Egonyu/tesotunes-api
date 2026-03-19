<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogClaimRequest;
use App\Services\CatalogClaimService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogClaimRequestAdminController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly CatalogClaimService $catalogClaimService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $this->ensureCanReviewClaims($request);

            $claims = CatalogClaimRequest::query()
                ->with(['artist', 'claimant', 'reviewer'])
                ->latest()
                ->paginate(min((int) $request->integer('per_page', 20), 100));

            return response()->json([
                'data' => $claims,
            ]);
        }, 'Failed to fetch catalog claim requests.');
    }

    public function approve(Request $request, CatalogClaimRequest $claim): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $claim) {
            $this->ensureCanReviewClaims($request);

            $approvedClaim = $this->catalogClaimService->approve($claim, $request->user());

            return response()->json([
                'message' => 'Claim request approved successfully.',
                'data' => $approvedClaim,
            ]);
        }, 'Failed to approve claim request.');
    }

    public function reject(Request $request, CatalogClaimRequest $claim): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $claim) {
            $this->ensureCanReviewClaims($request);

            $validated = $request->validate([
                'reason' => 'required|string|max:2000',
            ]);

            $rejectedClaim = $this->catalogClaimService->reject($claim, $request->user(), $validated['reason']);

            return response()->json([
                'message' => 'Claim request rejected successfully.',
                'data' => $rejectedClaim,
            ]);
        }, 'Failed to reject claim request.');
    }

    private function ensureCanReviewClaims(Request $request): void
    {
        $user = $request->user();

        if ($user->hasAnyRole(['admin', 'super_admin']) || $user->hasPermission('catalog.claim.review')) {
            return;
        }

        abort(403, 'You do not have permission to review catalog claims.');
    }
}
