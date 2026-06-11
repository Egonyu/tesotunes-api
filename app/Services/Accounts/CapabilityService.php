<?php

namespace App\Services\Accounts;

use App\Enums\Capability;
use App\Enums\CapabilityStatus;
use App\Enums\KycStatus;
use App\Models\Accounts\UserCapability;
use App\Models\User;
use App\Services\Kyc\KycService;
use Illuminate\Database\Eloquent\Model;

/**
 * The only writer of capability grants.
 * Lifecycle: apply (pending) -> grant | reject; granted -> suspend -> grant;
 * granted -> revoke. Rejected/revoked users may re-apply.
 */
class CapabilityService
{
    public function __construct(private readonly KycService $kyc) {}

    /**
     * Whether the user currently holds an active grant for the capability.
     */
    public function has(User $user, Capability $capability): bool
    {
        return UserCapability::query()
            ->where('user_id', $user->id)
            ->ofCapability($capability)
            ->granted()
            ->exists();
    }

    public function statusFor(User $user, Capability $capability): ?UserCapability
    {
        return UserCapability::query()
            ->where('user_id', $user->id)
            ->ofCapability($capability)
            ->first();
    }

    /**
     * Submit (or re-submit) an application for a capability.
     */
    public function apply(User $user, Capability $capability, array $application = []): UserCapability
    {
        $existing = $this->statusFor($user, $capability);

        if ($existing && $existing->status === CapabilityStatus::Granted) {
            return $existing;
        }

        if ($existing && $existing->status === CapabilityStatus::Pending) {
            return $existing;
        }

        if ($existing && $existing->status === CapabilityStatus::Suspended) {
            throw new \LogicException('This capability is suspended — contact support instead of re-applying.');
        }

        if ($existing) {
            // Rejected or revoked: re-application resets the lifecycle.
            $existing->forceFill([
                'status' => CapabilityStatus::Pending,
                'applied_at' => now(),
                'granted_at' => null,
                'revoked_at' => null,
                'status_reason' => null,
                'application' => $application ?: $existing->application,
            ])->save();

            return $existing;
        }

        return UserCapability::create([
            'user_id' => $user->id,
            'capability' => $capability,
            'application' => $application ?: null,
        ]);
    }

    /**
     * Grant a capability, optionally enforcing the shared KYC gate and
     * linking the backing domain profile (artist, store, promoter profile).
     */
    public function grant(
        User $user,
        Capability $capability,
        ?Model $profile = null,
        ?User $grantedBy = null,
        bool $requireKyc = false
    ): UserCapability {
        if ($requireKyc && $this->kyc->currentStatus($user) !== KycStatus::Verified) {
            throw new \DomainException('Identity verification (KYC) must be completed before this capability can be granted.');
        }

        $grant = $this->statusFor($user, $capability) ?? UserCapability::create([
            'user_id' => $user->id,
            'capability' => $capability,
        ]);

        if ($profile) {
            $grant->profile()->associate($profile);
        }

        $grant->forceFill([
            'status' => CapabilityStatus::Granted,
            'granted_at' => $grant->granted_at ?? now(),
            'granted_by_user_id' => $grantedBy?->id,
            'suspended_at' => null,
            'revoked_at' => null,
            'status_reason' => null,
        ])->save();

        return $grant;
    }

    public function reject(UserCapability $grant, string $reason, ?User $by = null): UserCapability
    {
        if ($grant->status !== CapabilityStatus::Pending) {
            throw new \LogicException("Only pending applications can be rejected (current status: {$grant->status->value}).");
        }

        $grant->forceFill([
            'status' => CapabilityStatus::Rejected,
            'status_reason' => $reason,
            'granted_by_user_id' => $by?->id,
        ])->save();

        return $grant;
    }

    public function suspend(UserCapability $grant, string $reason, ?User $by = null): UserCapability
    {
        if ($grant->status !== CapabilityStatus::Granted) {
            throw new \LogicException('Only granted capabilities can be suspended.');
        }

        $grant->forceFill([
            'status' => CapabilityStatus::Suspended,
            'suspended_at' => now(),
            'status_reason' => $reason,
            'granted_by_user_id' => $by?->id,
        ])->save();

        return $grant;
    }

    public function revoke(UserCapability $grant, string $reason, ?User $by = null): UserCapability
    {
        $grant->forceFill([
            'status' => CapabilityStatus::Revoked,
            'revoked_at' => now(),
            'status_reason' => $reason,
            'granted_by_user_id' => $by?->id,
        ])->save();

        return $grant;
    }
}
