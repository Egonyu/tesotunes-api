<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TwoFactorSettingsController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactorService) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $recoveryCodes = $this->twoFactorService->decodeRecoveryCodes($user->two_factor_recovery_codes);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => count($recoveryCodes),
            ],
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->twoFactorService->createSetupPayload($request->user()),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        try {
            $recoveryCodes = $this->twoFactorService->confirmCode($request->user(), $validated['code']);
        } catch (\InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'recovery_codes' => $recoveryCodes,
            ],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();
        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect password.',
            ], 422);
        }

        $user->disableTwoFactor();

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication disabled.',
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is not enabled.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'recovery_codes' => $this->twoFactorService->regenerateRecoveryCodes($user),
            ],
        ]);
    }
}
