<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserSetting;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

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
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
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

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => new UserResource($this->loadAuthRelations($user, ['settings'])),
            'token' => $token,
            'token_type' => 'Bearer',
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

        $user->update(['last_login_at' => now()]);

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

        if (! $user->hasAnyRole(['admin', 'super_admin'])) {
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
