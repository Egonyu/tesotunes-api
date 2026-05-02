<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RecaptchaService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Handles password reset flow for the SPA frontend.
 *
 * Flow:
 *   1. User requests reset → POST /api/auth/forgot-password → email sent
 *   2. User clicks link → frontend extracts token, email
 *   3. Frontend calls POST /api/auth/reset-password with token + email + new password
 */
class PasswordResetController extends Controller
{
    /**
     * POST /api/auth/forgot-password
     *
     * Send a password reset link to the given email.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'recaptcha_token' => 'nullable|string',
        ]);

        if (! app(RecaptchaService::class)->verify($request->recaptcha_token, 'forgot_password')) {
            return response()->json([
                'message' => 'Security verification failed. Please try again.',
            ], 422);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => __($status),
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }

    /**
     * POST /api/auth/reset-password
     *
     * Reset the user's password using the token from the email link.
     * The frontend extracts the token and email from the reset URL
     * and sends them here together with the new password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all existing tokens so the user must log in fresh
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }
}
