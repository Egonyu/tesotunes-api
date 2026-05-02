<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserSetting;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    protected function buildAuthenticatedResponse(User $user, string $token): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($this->loadAuthRelations($user, [
                'settings',
                'subscription',
                'profile',
                'referralProfile',
            ])),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Resolve the login throttle key used by the route limiter.
     */
    private function loginThrottleKey(Request $request): string
    {
        $identifier = Str::lower(trim((string) $request->input('email')));

        return $identifier !== ''
            ? "login:{$identifier}|{$request->ip()}"
            : "login-ip:{$request->ip()}";
    }

    /**
     * Clear accumulated failed login attempts after a successful sign-in.
     */
    private function clearLoginThrottle(Request $request): void
    {
        // The named throttle middleware hashes keys as md5($limiterName.$key).
        RateLimiter::clear(md5('login'.$this->loginThrottleKey($request)));
    }

    /**
     * Only eager-load auth relations that exist in the current schema.
     */
    private function loadAuthRelations(User $user, array $relations = []): User
    {
        $relationTableMap = [
            'settings' => 'user_settings',
            'subscription' => 'user_subscriptions',
            'profile' => 'user_profiles',
            'referralProfile' => 'user_referrals',
            'artist' => 'artists',
        ];

        $loadableRelations = array_values(array_filter($relations, function (string $relation) use ($relationTableMap) {
            $table = $relationTableMap[$relation] ?? null;

            return $table === null || Schema::hasTable($table);
        }));

        return empty($loadableRelations) ? $user : $user->load($loadableRelations);
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->whereNull('deleted_at')],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:2',
            'date_of_birth' => 'nullable|date|before:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'country' => $request->country ?? 'UG',
            'date_of_birth' => $request->date_of_birth,
            'role' => 'user',
            // HIGH-6 fix: Do NOT auto-verify email — require email verification flow
            // 'email_verified_at' => now(), // REMOVED: security risk
        ]);

        try {
            if (Schema::hasTable('user_settings')) {
                UserSetting::createDefault($user);
            }
        } catch (\Throwable $e) {
            Log::error('auth.register.settings_initialization_failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        event(new Registered($user));
        Log::channel('audit')->info('auth.email_verification.dispatch_requested', [
            'trigger' => 'registration',
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);

        try {
            $user->notify(new WelcomeNotification);
        } catch (\Throwable $e) {
            Log::error('auth.register.welcome_notification_failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Registration successful. Please verify your email before signing in.',
            'data' => new UserResource($this->loadAuthRelations($user, ['settings'])),
            'requires_email_verification' => true,
        ], 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'remember_me' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            Log::channel('security')->warning('auth.login.failed', [
                'email' => $request->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'reason' => 'invalid_credentials',
            ]);

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $user->is_active) {
            Log::channel('security')->warning('auth.login.blocked', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'reason' => 'account_suspended',
            ]);

            return response()->json([
                'message' => 'Account is suspended',
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            Log::channel('security')->warning('auth.login.blocked', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'reason' => 'email_not_verified',
            ]);

            return response()->json([
                'message' => 'Please verify your email before signing in.',
                'code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);
        $this->clearLoginThrottle($request);

        // Security alert for new login
        $user->notify(new SecurityAlertNotification(
            SecurityAlertNotification::NEW_LOGIN,
            [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'time' => now()->format('M d, Y H:i'),
            ]
        ));

        $tokenName = $request->remember_me ? 'long_lived_token' : 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        Log::channel('audit')->info('auth.login.succeeded', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'remember_me' => (bool) $request->boolean('remember_me'),
        ]);

        return $this->buildAuthenticatedResponse($user, $token);
    }

    /**
     * POST /api/auth/local-admin-login
     *
     * Local development fallback for admin accounts when the running stack still
     * surfaces legacy email-verification enforcement.
     */
    public function localAdminLogin(Request $request): JsonResponse
    {
        if (! app()->isLocal() && ! app()->runningUnitTests()) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'remember_me' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $user->hasAnyRole(['admin', 'super_admin', 'moderator'])) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Account is suspended',
            ], 403);
        }

        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->update(['last_login_at' => now()]);

        $tokenName = $request->boolean('remember_me') ? 'long_lived_token' : 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        Log::channel('audit')->info('auth.local_admin_login.succeeded', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);

        return $this->buildAuthenticatedResponse($user, $token);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * GET /api/user
     */
    public function user(Request $request)
    {
        $user = $this->loadAuthRelations($request->user(), [
            'settings',
            'subscription',
            'artist',
            'profile',
            'referralProfile',
        ]);

        return new UserResource($user);
    }
}
