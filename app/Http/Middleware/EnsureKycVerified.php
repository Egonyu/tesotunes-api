<?php

namespace App\Http\Middleware;

use App\Services\Kyc\KycService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind KYC verification for a specific action.
 *
 * Usage in routes:
 *   ->middleware('kyc:withdrawal')
 *   ->middleware('kyc:music_claim')
 *
 * When the user does not meet requirements, returns a structured
 * 403 response that the frontend uses to render the appropriate
 * "complete verification" stepper instead of a raw error.
 */
class EnsureKycVerified
{
    public function __construct(private readonly KycService $kyc) {}

    public function handle(Request $request, Closure $next, string $action = KycService::ACTION_WITHDRAWAL): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        if ($this->kyc->eligibleFor($user, $action)) {
            return $next($request);
        }

        $payload = $this->kyc->rejectionPayload($user, $action);

        return response()->json([
            'success' => false,
            'message' => 'Identity verification required to perform this action.',
            ...$payload,
        ], 403);
    }
}
