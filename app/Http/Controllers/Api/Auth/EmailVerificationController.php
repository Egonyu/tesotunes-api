<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles email verification for the SPA frontend.
 *
 * Flow:
 *   1. User registers → VerifyEmailNotification sent automatically
 *   2. User clicks link → frontend calls POST /api/auth/email/verify with id + hash
 *   3. Backend verifies hash, marks email_verified_at
 *
 * @see HIGH-6 in PLATFORM_STABILITY_AUDIT.md
 */
class EmailVerificationController extends Controller
{
    /**
     * POST /api/auth/email/verify
     *
     * Verify the user's email using the signed URL parameters.
     * The frontend extracts {id} and {hash} from the verification URL
     * and sends them here as POST body (SPA-friendly, no browser redirect).
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer',
            'hash' => 'required|string',
            'expires' => 'required|integer',
            'signature' => 'required|string',
        ]);

        $user = \App\Models\User::findOrFail($request->id);

        // Verify the hash matches the user's email
        if (! hash_equals(sha1($user->getEmailForVerification()), $request->hash)) {
            return response()->json([
                'message' => 'Invalid verification link.',
            ], 403);
        }

        // Check if link has expired
        if (now()->timestamp > $request->expires) {
            return response()->json([
                'message' => 'Verification link has expired. Please request a new one.',
            ], 410);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    /**
     * POST /api/auth/email/resend
     *
     * Resend the verification email to the authenticated user.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        $user->notify(new VerifyEmailNotification);

        return response()->json([
            'message' => 'Verification email sent.',
        ]);
    }

    /**
     * GET /api/auth/email/verify/status
     *
     * Check if the authenticated user's email is verified.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'verified' => $request->user()->hasVerifiedEmail(),
            'email' => $request->user()->email,
        ]);
    }
}
