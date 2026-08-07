<?php

namespace App\Services\Wallet;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The only writer of the wallet PIN fields on users (mirrors KycService's
 * discipline for kyc_status).
 *
 * The PIN authorizes money movement. It is stored hashed, never returned, and
 * never logged. A successful verification unlocks a short-lived window on that
 * session only, so a user confirming several actions is not re-prompted for
 * every one — while a stolen session still cannot move money without the PIN.
 */
class WalletPinService
{
    public function hasPin(User $user): bool
    {
        return filled($user->wallet_pin);
    }

    /**
     * Set the initial PIN. Refuses to silently overwrite an existing PIN —
     * changing one goes through changePin() with the current PIN.
     */
    public function setPin(User $user, string $pin): void
    {
        if ($this->hasPin($user)) {
            throw ValidationException::withMessages([
                'pin' => 'A wallet PIN is already set. Change it instead.',
            ]);
        }

        $this->assertPinIsAcceptable($pin);
        $this->persistPin($user, $pin);
    }

    /**
     * Replace an existing PIN, proving ownership with the current one.
     */
    public function changePin(User $user, string $currentPin, string $newPin): void
    {
        if (! $this->hasPin($user)) {
            throw ValidationException::withMessages([
                'pin' => 'No wallet PIN is set yet.',
            ]);
        }

        if (! $this->verify($user, $currentPin)) {
            throw ValidationException::withMessages([
                'current_pin' => 'That PIN is incorrect.',
            ]);
        }

        $this->assertPinIsAcceptable($newPin);
        $this->persistPin($user, $newPin);
    }

    /**
     * Check a PIN, counting failures and locking the PIN when they pile up.
     * Returns false for a wrong PIN; throws when the PIN is locked.
     */
    public function verify(User $user, string $pin): bool
    {
        if ($this->isLocked($user)) {
            throw ValidationException::withMessages([
                'pin' => 'Too many incorrect attempts. Try again in '.$this->minutesUntilUnlock($user).' minute(s).',
            ]);
        }

        if (! $this->hasPin($user)) {
            return false;
        }

        if (! Hash::check($pin, (string) $user->wallet_pin)) {
            $this->recordFailedAttempt($user);

            return false;
        }

        $user->forceFill([
            'wallet_pin_failed_attempts' => 0,
            'wallet_pin_locked_until' => null,
        ])->save();

        return true;
    }

    public function isLocked(User $user): bool
    {
        $until = $user->wallet_pin_locked_until;

        return $until !== null && $until->isFuture();
    }

    public function lockedUntil(User $user): ?CarbonInterface
    {
        return $this->isLocked($user) ? $user->wallet_pin_locked_until : null;
    }

    public function remainingAttempts(User $user): int
    {
        $max = (int) config('wallet.pin.max_attempts', 5);

        return max(0, $max - (int) $user->wallet_pin_failed_attempts);
    }

    /**
     * Open the short-lived money-movement window for one session.
     */
    public function unlockSession(User $user, string $sessionKey): CarbonInterface
    {
        $expiresAt = now()->addMinutes((int) config('wallet.pin.session_minutes', 5));

        Cache::put($this->sessionCacheKey($user, $sessionKey), true, $expiresAt);

        return $expiresAt;
    }

    public function sessionIsUnlocked(User $user, string $sessionKey): bool
    {
        return (bool) Cache::get($this->sessionCacheKey($user, $sessionKey), false);
    }

    public function lockSession(User $user, string $sessionKey): void
    {
        Cache::forget($this->sessionCacheKey($user, $sessionKey));
    }

    /**
     * Identifies the caller's session so an unlock never leaks across devices.
     *
     * Only a real personal access token identifies a device; session auth and
     * Sanctum's TransientToken (which has no key) fall back to a user-scoped
     * key.
     */
    public function sessionKeyFor(User $user): string
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            return 'token-'.$token->getKey();
        }

        return 'user-'.$user->id;
    }

    private function persistPin(User $user, string $pin): void
    {
        // The 'hashed' cast on User::casts() does the hashing.
        $user->forceFill([
            'wallet_pin' => $pin,
            'wallet_pin_set_at' => now(),
            'wallet_pin_failed_attempts' => 0,
            'wallet_pin_locked_until' => null,
        ])->save();
    }

    private function recordFailedAttempt(User $user): void
    {
        $attempts = (int) $user->wallet_pin_failed_attempts + 1;
        $max = (int) config('wallet.pin.max_attempts', 5);

        $user->forceFill([
            'wallet_pin_failed_attempts' => $attempts,
            'wallet_pin_locked_until' => $attempts >= $max
                ? now()->addMinutes((int) config('wallet.pin.lockout_minutes', 15))
                : null,
        ])->save();
    }

    private function assertPinIsAcceptable(string $pin): void
    {
        $length = (int) config('wallet.pin.length', 4);

        if (! preg_match('/^\d{'.$length.'}$/', $pin)) {
            throw ValidationException::withMessages([
                'pin' => "Your PIN must be exactly {$length} digits.",
            ]);
        }

        if (in_array($pin, (array) config('wallet.pin.blocklist', []), true)) {
            throw ValidationException::withMessages([
                'pin' => 'That PIN is too easy to guess. Choose a less obvious one.',
            ]);
        }
    }

    private function minutesUntilUnlock(User $user): int
    {
        return max(1, (int) ceil(now()->diffInSeconds($user->wallet_pin_locked_until, false) / 60));
    }

    private function sessionCacheKey(User $user, string $sessionKey): string
    {
        return "wallet:pin:unlocked:{$user->id}:{$sessionKey}";
    }
}
