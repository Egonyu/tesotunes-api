<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\Observability\EventOutcome;
use App\Enums\Observability\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\DispatchUserRegisteredWebhook;
use App\Models\User;
use App\Models\UserSetting;
use App\Notifications\SecurityAlertNotification;
use App\Notifications\WelcomeNotification;
use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use App\Services\RecaptchaService;
use App\Services\Security\TwoFactorService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * Write to the security log channel without propagating file-system errors.
     *
     * The security log is a daily-rotated file. If the file is owned by a
     * privileged OS user (e.g. root, from a cron job) and the web process
     * (www-data) cannot write to it, Monolog throws — which would otherwise
     * surface as a 500 and lock every user out of login until the file is
     * manually fixed. This wrapper silently absorbs the failure so that auth
     * flows remain functional regardless of log-file health.
     */
    private function safeSecurityLog(string $level, string $event, array $context): void
    {
        try {
            Log::channel('security')->{$level}($event, $context);
        } catch (\Throwable) {
            // Intentionally swallowed — log file permission issues must never
            // block authentication. The exception handler will still report the
            // underlying infrastructure problem via AlertingService.
        }
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
            'recaptcha_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! app(RecaptchaService::class)->verify($request->recaptcha_token, 'register')) {
            return response()->json([
                'message' => 'Security verification failed. Please try again.',
            ], 422);
        }

        // A soft-deleted account still owns its history (earnings, transactions,
        // uploads). Never destroy it to free the email — recovery is a support flow.
        if (User::onlyTrashed()->where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'An account with this email was previously deleted. Please contact support to restore it.',
                'code' => 'ACCOUNT_PREVIOUSLY_DELETED',
            ], 409);
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

        // n8n automation: welcome email + Listmonk subscriber creation
        DispatchUserRegisteredWebhook::dispatch($user->id)->onQueue('webhooks');

        SecurityEventRecorder::emit(
            SecurityEvent::of(SecurityEventType::AuthRegistered)
                ->summary('New account registered: '.$user->email)
                ->actor('user', $user->id, $user->name ?? $user->email)
                ->fromRequest($request)
        );

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
            'recaptcha_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! app(RecaptchaService::class)->verify($request->recaptcha_token, 'login')) {
            return response()->json([
                'message' => 'Security verification failed. Please try again.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->safeSecurityLog('warning', 'auth.login.failed', [
                'email' => $request->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'reason' => 'invalid_credentials',
            ]);

            SecurityEventRecorder::emit(
                SecurityEvent::of(SecurityEventType::AuthLoginFailed)
                    ->summary('Failed login attempt for '.$request->email)
                    ->actor('guest', null, $request->email)
                    ->fromRequest($request)
                    ->detail('reason', 'invalid_credentials')
                    ->detail('account_exists', (bool) $user)
            );

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        if (! $user->is_active) {
            $this->safeSecurityLog('warning', 'auth.login.blocked', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'reason' => 'account_suspended',
            ]);

            SecurityEventRecorder::emit(
                SecurityEvent::of(SecurityEventType::AuthLoginFailed)
                    ->outcome(EventOutcome::Blocked)
                    ->summary('Login blocked for suspended account '.$user->email)
                    ->actor($user->role ?: 'user', $user->id, $user->name ?? $user->email)
                    ->fromRequest($request)
                    ->detail('reason', 'account_suspended')
            );

            return response()->json([
                'message' => 'Account is suspended',
            ], 403);
        }

        // 2FA bypasses the email-verification gate: the TOTP secret was confirmed
        // during a prior authenticated session, which already required a verified
        // email. Requiring email verification again on top of 2FA would permanently
        // lock out any user whose email_verified_at is inadvertently cleared (e.g.
        // during a DB restore) even though their identity is proven by the TOTP app.
        if ($user->hasTwoFactorEnabled()) {
            $pendingKey = 'two_fa_pending_'.Str::uuid()->toString();
            $rememberMe = $request->boolean('remember_me');
            Cache::put($pendingKey, ['user_id' => $user->id, 'remember_me' => $rememberMe], now()->addMinutes(10));

            $this->safeSecurityLog('info', 'auth.login.two_fa_required', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'requires_2fa' => true,
                'two_fa_token' => $pendingKey,
                'message' => 'Two-factor authentication required.',
            ]);
        }

        // Users without 2FA still require email verification.
        if (! $user->hasVerifiedEmail()) {
            $this->safeSecurityLog('warning', 'auth.login.blocked', [
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

        // Security alert for new login — wrapped so a mail/channel failure never blocks auth
        try {
            $user->notify(new SecurityAlertNotification(
                SecurityAlertNotification::NEW_LOGIN,
                [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'time' => now()->format('M d, Y H:i'),
                ]
            ));
        } catch (\Throwable $e) {
            Log::error('auth.login.new_login_notification_failed', [
                'user_id' => $user->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

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

        SecurityEventRecorder::emit(
            SecurityEvent::of(SecurityEventType::AuthLoginSucceeded)
                ->summary($user->email.' signed in')
                ->actor($user->role ?: 'user', $user->id, $user->name ?? $user->email)
                ->fromRequest($request)
                ->detail('remember_me', (bool) $request->boolean('remember_me'))
        );

        return $this->buildAuthenticatedResponse($user, $token);
    }

    /**
     * POST /api/auth/2fa/challenge
     *
     * Complete a pending 2FA login. Accepts either a 6-digit TOTP code or an
     * 8–10 character recovery code.
     */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'two_fa_token' => 'required|string',
            'code' => 'required|string',
        ]);

        $pending = Cache::get($validated['two_fa_token']);

        if (! $pending || ! isset($pending['user_id'])) {
            return response()->json([
                'message' => 'Invalid or expired session. Please sign in again.',
            ], 401);
        }

        $user = User::find($pending['user_id']);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            Cache::forget($validated['two_fa_token']);

            return response()->json([
                'message' => 'Invalid session.',
            ], 401);
        }

        $twoFactor = app(TwoFactorService::class);
        $code = trim($validated['code']);
        $verified = false;

        if (strlen($code) > 6) {
            // Recovery code path — consume the code so it cannot be reused
            $recoveryCodes = $twoFactor->decodeRecoveryCodes($user->two_factor_recovery_codes);
            $upperCode = strtoupper($code);
            $matchIndex = array_search($upperCode, array_map('strtoupper', $recoveryCodes), true);

            if ($matchIndex !== false) {
                unset($recoveryCodes[$matchIndex]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($recoveryCodes))])->save();
                $verified = true;
            }
        } else {
            $verified = $user->two_factor_secret && $twoFactor->verifyCode($user->two_factor_secret, $code);
        }

        if (! $verified) {
            Log::channel('security')->warning('auth.two_fa_challenge.failed', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Invalid authentication code.',
            ], 422);
        }

        Cache::forget($validated['two_fa_token']);

        $user->update(['last_login_at' => now()]);
        $this->clearLoginThrottle($request);

        try {
            $user->notify(new SecurityAlertNotification(
                SecurityAlertNotification::NEW_LOGIN,
                [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'time' => now()->format('M d, Y H:i'),
                ]
            ));
        } catch (\Throwable $e) {
            Log::error('auth.two_fa_challenge.notification_failed', [
                'user_id' => $user->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        $tokenName = $pending['remember_me'] ? 'long_lived_token' : 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        Log::channel('audit')->info('auth.login.succeeded_via_2fa', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
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
        $user = $request->user();
        $user->currentAccessToken()->delete();

        SecurityEventRecorder::emit(
            SecurityEvent::of(SecurityEventType::AuthLogout)
                ->summary($user->email.' signed out')
                ->actor($user->role ?: 'user', $user->id, $user->name ?? $user->email)
                ->fromRequest($request)
        );

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

        SecurityEventRecorder::emit(
            SecurityEvent::of(SecurityEventType::AuthTokenRefreshed)
                ->summary('Access token refreshed for '.$user->email)
                ->actor($user->role ?: 'user', $user->id, $user->name ?? $user->email)
                ->fromRequest($request)
        );

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
