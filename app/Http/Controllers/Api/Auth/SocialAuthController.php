<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialAuthService $socialAuthService) {}

    public function providers(): JsonResponse
    {
        $providers = [
            [
                'key' => 'google',
                'label' => 'Google',
                'enabled' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
                'supports_id_token' => true,
                'supports_access_token' => true,
            ],
            [
                'key' => 'facebook',
                'label' => 'Facebook',
                'enabled' => filled(config('services.facebook.client_id')) && filled(config('services.facebook.client_secret')),
                'supports_id_token' => false,
                'supports_access_token' => true,
            ],
        ];

        return response()->json([
            'data' => $providers,
        ]);
    }

    public function exchange(Request $request, string $provider): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'nullable|string|required_without:id_token',
            'id_token' => 'nullable|string|required_without:access_token',
            'device_name' => 'nullable|string|max:100',
            'platform' => 'nullable|in:web,ios,android',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $this->socialAuthService->isProviderSupported($provider)) {
            return response()->json([
                'message' => 'Unsupported provider',
                'code' => 'UNSUPPORTED_PROVIDER',
            ], 422);
        }

        $providerToken = $provider === 'google'
            ? (string) ($request->input('id_token') ?: $request->input('access_token'))
            : (string) ($request->input('access_token') ?: $request->input('id_token'));
        $deviceName = (string) ($request->input('device_name') ?: 'auth_token');

        try {
            $result = $this->socialAuthService->authenticateWithProviderToken($provider, $providerToken);
            $user = $result['user'];

            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Account is suspended',
                    'code' => 'ACCOUNT_SUSPENDED',
                ], 403);
            }

            $token = $user->createToken($deviceName)->plainTextToken;

            return response()->json([
                'data' => new UserResource($this->loadAuthRelations($user)),
                'token' => $token,
                'token_type' => 'Bearer',
                'auth' => [
                    'provider' => $provider,
                    'linked_existing_email' => (bool) $result['linked_existing_email'],
                    'is_new_user' => (bool) $result['is_new_user'],
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'SOCIAL_EMAIL_REQUIRED') {
                return response()->json([
                    'message' => 'Email permission is required for social sign-in.',
                    'code' => 'SOCIAL_EMAIL_REQUIRED',
                ], 422);
            }

            if ($e->getMessage() === 'SOCIAL_EMAIL_UNVERIFIED') {
                return response()->json([
                    'message' => 'Verified provider email is required for social sign-in.',
                    'code' => 'SOCIAL_EMAIL_UNVERIFIED',
                ], 422);
            }

            return response()->json([
                'message' => 'Unsupported provider',
                'code' => 'UNSUPPORTED_PROVIDER',
            ], 422);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'ACCOUNT_SUSPENDED') {
                return response()->json([
                    'message' => 'Account is suspended',
                    'code' => 'ACCOUNT_SUSPENDED',
                ], 403);
            }

            return response()->json([
                'message' => 'Invalid social token',
                'code' => 'SOCIAL_TOKEN_INVALID',
            ], 401);
        } catch (Throwable $e) {
            Log::error('auth.social.exchange.failed', [
                'provider' => $provider,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to authenticate with social provider right now.',
            ], 500);
        }
    }

    private function loadAuthRelations($user)
    {
        $relationTableMap = [
            'settings' => 'user_settings',
            'subscription' => 'user_subscriptions',
            'profile' => 'user_profiles',
            'referralProfile' => 'user_referrals',
        ];

        $relations = [];
        foreach ($relationTableMap as $relation => $table) {
            if (Schema::hasTable($table)) {
                $relations[] = $relation;
            }
        }

        return empty($relations) ? $user : $user->loadMissing($relations);
    }
}
