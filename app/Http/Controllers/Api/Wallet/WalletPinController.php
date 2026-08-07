<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Wallet\SetWalletPinRequest;
use App\Services\Wallet\WalletPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wallet transaction PIN — set it, change it, and unlock a short-lived window
 * that authorizes money movement. The PIN itself is never returned or logged.
 */
class WalletPinController extends Controller
{
    public function __construct(private readonly WalletPinService $pins) {}

    /**
     * GET /api/wallet/pin/status — drives the "set up" vs "enter" PIN modal.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessionKey = $this->pins->sessionKeyFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'has_pin' => $this->pins->hasPin($user),
                'is_locked' => $this->pins->isLocked($user),
                'locked_until' => $this->pins->lockedUntil($user)?->toIso8601String(),
                'remaining_attempts' => $this->pins->remainingAttempts($user),
                'session_unlocked' => $this->pins->sessionIsUnlocked($user, $sessionKey),
                'pin_length' => (int) config('wallet.pin.length', 4),
                'session_minutes' => (int) config('wallet.pin.session_minutes', 5),
            ],
        ]);
    }

    /**
     * POST /api/wallet/pin — set the initial PIN.
     */
    public function store(SetWalletPinRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->pins->setPin($user, $request->string('pin')->toString());
        $expiresAt = $this->pins->unlockSession($user, $this->pins->sessionKeyFor($user));

        return response()->json([
            'success' => true,
            'message' => 'Wallet PIN set.',
            'data' => ['session_expires_at' => $expiresAt->toIso8601String()],
        ], 201);
    }

    /**
     * PUT /api/wallet/pin — change the PIN, proving ownership with the current one.
     */
    public function update(Request $request): JsonResponse
    {
        $length = (int) config('wallet.pin.length', 4);

        $request->validate([
            'current_pin' => ['required', 'string', 'digits:'.$length],
            'pin' => ['required', 'string', 'digits:'.$length],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ]);

        $user = $request->user();

        $this->pins->changePin(
            $user,
            $request->string('current_pin')->toString(),
            $request->string('pin')->toString(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Wallet PIN updated.',
        ]);
    }

    /**
     * POST /api/wallet/pin/verify — unlock the money-movement window.
     */
    public function verify(Request $request): JsonResponse
    {
        $length = (int) config('wallet.pin.length', 4);
        $request->validate(['pin' => ['required', 'string', 'digits:'.$length]]);

        $user = $request->user();

        if (! $this->pins->verify($user, $request->string('pin')->toString())) {
            return response()->json([
                'success' => false,
                'message' => 'That PIN is incorrect.',
                'data' => ['remaining_attempts' => $this->pins->remainingAttempts($user->refresh())],
            ], 422);
        }

        $expiresAt = $this->pins->unlockSession($user, $this->pins->sessionKeyFor($user));

        return response()->json([
            'success' => true,
            'message' => 'PIN verified.',
            'data' => ['session_expires_at' => $expiresAt->toIso8601String()],
        ]);
    }

    /**
     * POST /api/wallet/pin/lock — end the unlocked window early.
     */
    public function lock(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->pins->lockSession($user, $this->pins->sessionKeyFor($user));

        return response()->json(['success' => true, 'message' => 'Wallet locked.']);
    }
}
