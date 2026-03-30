<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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
    private const RESEND_DISPATCHED_MESSAGE = 'If your account still requires verification, we have sent a fresh verification email.';

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

        $user = User::findOrFail($request->integer('id'));
        $failure = $this->validateVerificationLink(
            $user,
            $request->integer('id'),
            (string) $request->string('hash'),
            $request->integer('expires'),
            $request->input('signature')
        );

        if ($failure) {
            return response()->json([
                'message' => $failure['message'],
            ], $failure['status']);
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
     * GET /email/verify/{id}/{hash}
     *
     * Complete verification from the signed email link, then redirect to the
     * frontend verification page with a stable status query string.
     */
    public function redirect(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->redirectToFrontend('failed', 'user-not-found');
        }

        $failure = $this->validateVerificationLink(
            $user,
            $id,
            $hash,
            $request->integer('expires'),
            $request->query('signature')
        );

        if ($failure) {
            return $this->redirectToFrontend('failed', $failure['reason'] ?? 'invalid-signature');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->redirectToFrontend('already-verified');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $this->redirectToFrontend('verified');
    }

    /**
     * POST /api/auth/email/resend
     *
     * Resend the verification email to the authenticated user.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            $request->validate([
                'email' => 'required|email',
            ]);

            $normalizedEmail = mb_strtolower(trim((string) $request->input('email')));
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->first();

            if (! $user) {
                Log::channel('security')->info('auth.email_verification.resend_ignored', [
                    'reason' => 'unknown_email',
                    'email' => $normalizedEmail,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'url' => $request->fullUrl(),
                ]);

                return response()->json([
                    'message' => self::RESEND_DISPATCHED_MESSAGE,
                ]);
            }
        }

        if ($user->hasVerifiedEmail()) {
            Log::channel('audit')->info('auth.email_verification.resend_ignored', [
                'reason' => 'already_verified',
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json([
                'message' => self::RESEND_DISPATCHED_MESSAGE,
            ]);
        }

        Log::channel('audit')->info('auth.email_verification.dispatch_requested', [
            'trigger' => 'resend',
            'user_id' => $user->id,
            'email' => $user->email,
            'requested_by_user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);

        $user->notify(new VerifyEmailNotification);

        return response()->json([
            'message' => self::RESEND_DISPATCHED_MESSAGE,
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

    /**
     * Validate the signed verification payload without relying on the current
     * request path. This supports both direct GET links and the SPA POST flow.
     *
     * @return array{status:int,message:string,reason?:string}|null
     */
    private function validateVerificationLink(
        User $user,
        int $id,
        string $hash,
        ?int $expires,
        mixed $signature
    ): ?array {
        if (! hash_equals((string) $user->getKey(), (string) $id)) {
            return [
                'status' => 403,
                'message' => 'Invalid verification link.',
                'reason' => 'invalid-user',
            ];
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return [
                'status' => 403,
                'message' => 'Invalid verification link.',
                'reason' => 'invalid-hash',
            ];
        }

        if (! $expires || ! is_string($signature) || trim($signature) === '') {
            return [
                'status' => 403,
                'message' => 'Invalid verification link.',
                'reason' => 'missing-parameters',
            ];
        }

        if (now()->timestamp > $expires) {
            return [
                'status' => 410,
                'message' => 'Verification link has expired. Please request a new one.',
                'reason' => 'expired',
            ];
        }

        $verificationRequest = Request::create(
            route('verification.verify', [
                'id' => $id,
                'hash' => $hash,
                'expires' => $expires,
                'signature' => $signature,
            ]),
            'GET'
        );

        if (! URL::hasValidSignature($verificationRequest)) {
            return [
                'status' => 403,
                'message' => 'Invalid verification link.',
                'reason' => 'invalid-signature',
            ];
        }

        return null;
    }

    private function redirectToFrontend(string $status, ?string $reason = null): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query(array_filter([
            'status' => $status,
            'reason' => $reason,
        ]));

        return redirect()->away("{$frontendUrl}/verify-email".($query ? "?{$query}" : ''));
    }
}
