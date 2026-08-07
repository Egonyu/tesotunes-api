<?php

namespace App\Http\Middleware;

use App\Services\Wallet\WalletPinService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an unlocked wallet PIN session before money can move.
 *
 * Usage: ->middleware('wallet.pin')
 *
 * Responds 423 (Locked) with a machine-readable `pin_status` so the frontend
 * knows whether to show the "set up your PIN" or the "enter your PIN" modal,
 * rather than a generic failure.
 */
class EnsureWalletPinVerified
{
    public function __construct(private readonly WalletPinService $pins) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Dark until the PIN UI is live — see config/wallet.php.
        if (! config('wallet.pin.enforce', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        if (! $this->pins->hasPin($user)) {
            return response()->json([
                'success' => false,
                'pin_status' => 'setup_required',
                'message' => 'Set up your wallet PIN to authorize this transaction.',
            ], 423);
        }

        if ($this->pins->isLocked($user)) {
            return response()->json([
                'success' => false,
                'pin_status' => 'locked',
                'locked_until' => $this->pins->lockedUntil($user)?->toIso8601String(),
                'message' => 'Too many incorrect PIN attempts. Please try again later.',
            ], 423);
        }

        if (! $this->pins->sessionIsUnlocked($user, $this->pins->sessionKeyFor($user))) {
            return response()->json([
                'success' => false,
                'pin_status' => 'verification_required',
                'message' => 'Enter your wallet PIN to authorize this transaction.',
            ], 423);
        }

        return $next($request);
    }
}
