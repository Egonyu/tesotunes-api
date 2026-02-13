<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\AuditLog;
use App\Services\ProfileCompletionService;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Social Authentication Service
 * 
 * Handles OAuth authentication with Google and Facebook
 * Integrates with existing auth system and modules (SACCO, Store, Podcast)
 */
class SocialAuthService
{
    protected ProfileCompletionService $profileService;

    public function __construct(ProfileCompletionService $profileService)
    {
        $this->profileService = $profileService;
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
            Log::error('Social auth error: ' . $e->getMessage(), [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
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
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken ?? null,
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
                    'provider_token' => $socialUser->token,
                    'provider_refresh_token' => $socialUser->refreshToken ?? null,
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
            $name = $socialUser->getName() ?: 'User' . Str::random(6);
            
            // Check if name already exists and make it unique
            $originalName = $name;
            $counter = 1;
            while (User::where('name', $name)->exists()) {
                $name = $originalName . $counter;
                $counter++;
            }

            $user = User::create([
                'name' => $name,
                'email' => $socialUser->getEmail(),
                'avatar' => $socialUser->getAvatar(),
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken ?? null,
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
            $user->notifications()->create([
                'type' => 'welcome',
                'title' => 'Welcome to LineOne Music!',
                'message' => 'Start exploring music from Uganda and East Africa',
                'action_url' => route('frontend.home'),
            ]);

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
            return route('frontend.auth.phone-verification');
        }

        // Admin/moderator/finance access
        if ($user->canAccessAdminPanel()) {
            return route('admin.dashboard');
        }

        // Artist dashboard
        if ($user->isVerified() && $user->canAccessArtistDashboard()) {
            return route('frontend.artist.dashboard');
        }

        // Pending verification
        if ($user->isPendingVerification()) {
            return route('frontend.dashboard');
        }

        // Check if user has intended URL in session
        if (session()->has('url.intended')) {
            return session()->pull('url.intended');
        }

        // Default to frontend dashboard/home
        return route('frontend.dashboard');
    }

    /**
     * Revoke social authentication (unlink provider)
     */
    public function revokeSocialAuth(User $user): bool
    {
        if (!$user->provider) {
            return false;
        }

        // Check if user has a password set (can't unlink if no alternative auth)
        if (!$user->password || $user->password === bcrypt(Str::random(32))) {
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
        if (!$user->provider || !$user->provider_refresh_token) {
            return null;
        }

        try {
            $socialUser = Socialite::driver($user->provider)
                ->refreshToken($user->provider_refresh_token)
                ->user();

            $user->update([
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken ?? $user->provider_refresh_token,
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
}
