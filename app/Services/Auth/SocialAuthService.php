<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\Notification as AppNotification;
use App\Models\User;
use App\Services\ProfileCompletionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

/**
 * Social Authentication Service
 *
 * Handles OAuth authentication with Google and Facebook
 * Integrates with existing auth system and modules (SACCO, Store, Podcast)
 */
class SocialAuthService
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_PROVIDERS = ['google', 'facebook'];

    protected ProfileCompletionService $profileService;

    public function __construct(ProfileCompletionService $profileService)
    {
        $this->profileService = $profileService;
    }

    public function isProviderSupported(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * Authenticate a user from a provider token and return resolution metadata.
     *
     * @return array{user: User, linked_existing_email: bool, is_new_user: bool}
     */
    public function authenticateWithProviderToken(string $provider, string $providerToken): array
    {
        if (! $this->isProviderSupported($provider)) {
            throw new InvalidArgumentException('UNSUPPORTED_PROVIDER');
        }

        try {
            $driver = Socialite::driver($provider);
            if (method_exists($driver, 'stateless')) {
                $driver = call_user_func([$driver, 'stateless']);
            }

            $socialUser = $driver->userFromToken($providerToken);
        } catch (\Throwable $e) {
            Log::warning('Social provider token exchange failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Provider token validation failed.', 0, $e);
        }

        return $this->resolveUserFromSocial($socialUser, $provider);
    }

    /**
     * Handle OAuth callback from provider
     */
    public function handleCallback(string $provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();

            // Find or create user
            $user = $this->findOrCreateUser($socialUser, $provider);

            // Update last login tracking
            $user->updateLastLogin('web');
            $user->updateOnlineStatus();

            // Calculate and update profile completion
            $this->profileService->updateCompletion($user);

            // Log activity
            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'social_login',
                'new_values' => [
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
            ]);

            // Return redirect URL based on profile completion and role
            return [
                'user' => $user,
                'redirect' => $this->getRedirectUrl($user),
                'suggest_profile_completion' => $user->profile_completion_percentage < 70,
            ];

        } catch (\Exception $e) {
            Log::error('Social auth error: '.$e->getMessage(), [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * @return array{user: User, linked_existing_email: bool, is_new_user: bool}
     */
    protected function resolveUserFromSocial($socialUser, string $provider): array
    {
        $existingByProvider = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($existingByProvider) {
            if (! $existingByProvider->is_active) {
                throw new RuntimeException('ACCOUNT_SUSPENDED');
            }

            $existingByProvider->update([
                'provider_token' => $this->protectSensitiveToken($socialUser->token),
                'provider_refresh_token' => $this->protectSensitiveToken($socialUser->refreshToken ?? null),
            ]);

            $existingByProvider->updateLastLogin('web');
            $existingByProvider->updateOnlineStatus();
            $this->profileService->updateCompletion($existingByProvider);

            return [
                'user' => $existingByProvider,
                'linked_existing_email' => false,
                'is_new_user' => false,
            ];
        }

        if (blank($socialUser->getEmail())) {
            throw new InvalidArgumentException('SOCIAL_EMAIL_REQUIRED');
        }

        if (! $this->isProviderEmailVerified($socialUser, $provider)) {
            throw new InvalidArgumentException('SOCIAL_EMAIL_UNVERIFIED');
        }

        $existingByEmail = User::where('email', $socialUser->getEmail())->first();

        if ($existingByEmail) {
            if (! $existingByEmail->is_active) {
                throw new RuntimeException('ACCOUNT_SUSPENDED');
            }

            $existingByEmail->update([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_token' => $this->protectSensitiveToken($socialUser->token),
                'provider_refresh_token' => $this->protectSensitiveToken($socialUser->refreshToken ?? null),
            ]);

            if (! $existingByEmail->hasVerifiedEmail()) {
                $existingByEmail->forceFill(['email_verified_at' => now()])->save();
            }

            $existingByEmail->updateLastLogin('web');
            $existingByEmail->updateOnlineStatus();
            $this->profileService->updateCompletion($existingByEmail);

            return [
                'user' => $existingByEmail,
                'linked_existing_email' => true,
                'is_new_user' => false,
            ];
        }

        $newUser = $this->createUserFromSocial($socialUser, $provider);
        if (! $newUser->hasVerifiedEmail()) {
            $newUser->forceFill(['email_verified_at' => now()])->save();
        }
        $newUser->updateLastLogin('web');
        $newUser->updateOnlineStatus();
        $this->profileService->updateCompletion($newUser);

        return [
            'user' => $newUser,
            'linked_existing_email' => false,
            'is_new_user' => true,
        ];
    }

    /**
     * Find existing user or create new one from social data
     */
    protected function findOrCreateUser($socialUser, string $provider): User
    {
        // Check if user exists with this provider
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($user) {
            // Update social token
            $user->update([
                'provider_token' => $this->protectSensitiveToken($socialUser->token),
                'provider_refresh_token' => $this->protectSensitiveToken($socialUser->refreshToken ?? null),
            ]);

            return $user;
        }

        // Check if user exists with this email (link social account)
        if ($socialUser->getEmail()) {
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user) {
                // Link social account to existing user
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'provider_token' => $this->protectSensitiveToken($socialUser->token),
                    'provider_refresh_token' => $this->protectSensitiveToken($socialUser->refreshToken ?? null),
                    'email_verified_at' => $user->email_verified_at ?? now(), // Verify if not already
                ]);

                Log::info('Social account linked to existing user', [
                    'user_id' => $user->id,
                    'provider' => $provider,
                ]);

                return $user;
            }
        }

        // Create new user
        return $this->createUserFromSocial($socialUser, $provider);
    }

    /**
     * Create new user from social provider data
     */
    protected function createUserFromSocial($socialUser, string $provider): User
    {
        return DB::transaction(function () use ($socialUser, $provider) {
            // Generate unique name if needed
            $name = $socialUser->getName() ?: 'User'.Str::random(6);

            // Check if name already exists and make it unique
            $originalName = $name;
            $counter = 1;
            while (User::where('name', $name)->exists()) {
                $name = $originalName.$counter;
                $counter++;
            }

            $user = User::create([
                'name' => $name,
                'email' => $socialUser->getEmail(),
                'avatar' => $socialUser->getAvatar(),
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_token' => $this->protectSensitiveToken($socialUser->token),
                'provider_refresh_token' => $this->protectSensitiveToken($socialUser->refreshToken ?? null),
                'email_verified_at' => now(), // Social accounts are pre-verified
                'password' => bcrypt(Str::random(32)), // Random password (not usable)
                'role' => 'user', // Default role
                'status' => 'active',
                'is_active' => true,
                'profile_completion_percentage' => 30, // Basic info from social
                'profile_steps_completed' => json_encode(['social_signup', 'email']),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Assign default user role if role system is set up
            if (class_exists(\App\Models\Role::class)) {
                $defaultRole = \App\Models\Role::where('name', 'user')->first();
                if ($defaultRole) {
                    $user->roles()->attach($defaultRole->id, [
                        'assigned_at' => now(),
                        'is_active' => true,
                    ]);
                }
            }

            // Create default user settings
            if (class_exists(\App\Models\UserSetting::class)) {
                \App\Models\UserSetting::createDefault($user);
            }

            // Send welcome notification
            AppNotification::createRichForUser(
                $user,
                'welcome',
                'Welcome to LineOne Music!',
                'Start exploring music from Uganda and East Africa',
                [],
                $this->resolveRoute('frontend.home', '/'),
                'auth'
            );

            Log::info('New user created via social auth', [
                'user_id' => $user->id,
                'provider' => $provider,
                'email' => $user->email,
            ]);

            return $user;
        });
    }

    /**
     * Get redirect URL based on user state
     */
    protected function getRedirectUrl(User $user): string
    {
        // Check if user needs phone verification (for artists or security)
        if ($user->requiresPhoneVerification()) {
            return $this->resolveRoute('frontend.auth.phone-verification', '/auth/phone-verification');
        }

        // Admin/moderator/finance access
        if ($user->canAccessAdminPanel()) {
            return $this->resolveRoute('admin.dashboard', '/admin');
        }

        // Artist dashboard
        if ($user->isVerified() && $user->canAccessArtistDashboard()) {
            return $this->resolveRoute('frontend.artist.dashboard', '/artist/dashboard');
        }

        // Pending verification
        if ($user->isPendingVerification()) {
            return $this->resolveRoute('frontend.dashboard', '/dashboard');
        }

        // Check if user has intended URL in session
        if (session()->has('url.intended')) {
            return session()->pull('url.intended');
        }

        // Default to frontend dashboard/home
        return $this->resolveRoute('frontend.dashboard', '/dashboard');
    }

    /**
     * Revoke social authentication (unlink provider)
     */
    public function revokeSocialAuth(User $user): bool
    {
        if (! $user->provider) {
            return false;
        }

        // Check if user has a password set (can't unlink if no alternative auth)
        if (! $user->password || $user->password === bcrypt(Str::random(32))) {
            throw new \Exception('Cannot unlink social account without setting a password first');
        }

        $provider = $user->provider;

        $user->update([
            'provider' => null,
            'provider_id' => null,
            'provider_token' => null,
            'provider_refresh_token' => null,
        ]);

        Log::info('Social auth revoked', [
            'user_id' => $user->id,
            'provider' => $provider,
        ]);

        return true;
    }

    /**
     * Refresh social token if needed
     */
    public function refreshToken(User $user): ?string
    {
        if (! $user->provider || ! $user->provider_refresh_token) {
            return null;
        }

        try {
            $driver = Socialite::driver($user->provider);
            if (! method_exists($driver, 'refreshToken')) {
                return null;
            }

            $refreshToken = $this->unprotectSensitiveToken($user->provider_refresh_token);
            if (blank($refreshToken)) {
                return null;
            }

            $socialUser = $driver
                ->{'refreshToken'}($refreshToken)
                ->user();

            $user->update([
                'provider_token' => $this->protectSensitiveToken($socialUser->token),
                'provider_refresh_token' => $this->protectSensitiveToken($socialUser->refreshToken ?? $refreshToken),
            ]);

            return $socialUser->token;

        } catch (\Exception $e) {
            Log::error('Token refresh failed', [
                'user_id' => $user->id,
                'provider' => $user->provider,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function resolveRoute(string $name, string $fallback, mixed ...$parameters): string
    {
        return Route::has($name)
            ? route($name, ...$parameters)
            : url($fallback);
    }

    private function protectSensitiveToken(?string $token): ?string
    {
        if (blank($token)) {
            return null;
        }

        return Crypt::encryptString($token);
    }

    private function unprotectSensitiveToken(?string $token): ?string
    {
        if (blank($token)) {
            return null;
        }

        try {
            return Crypt::decryptString($token);
        } catch (\Throwable) {
            // Backward compatibility for records created before encryption.
            return $token;
        }
    }

    private function isProviderEmailVerified($socialUser, string $provider): bool
    {
        if ($provider !== 'google') {
            return true;
        }

        $rawUser = $socialUser->user ?? null;
        if (! is_array($rawUser)) {
            return true;
        }

        $verified = $rawUser['email_verified'] ?? null;
        if ($verified === null) {
            return true;
        }

        return (bool) $verified;
    }
}
