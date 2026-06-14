<?php

namespace App\Modules\Contributions\Services;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Services\Accounts\CapabilityService;
use Illuminate\Support\Facades\DB;

/**
 * Captures the one-time data-terms consent that gates participation in the
 * corpus pipeline, and grants the Contributor capability. Contributing itself
 * needs no KYC (low friction); KYC only gates the eventual payout of earned
 * credits, enforced on the settlement/withdrawal side.
 */
class ConsentService
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function currentTermsVersion(): string
    {
        return (string) config('contributions.terms_version');
    }

    /**
     * Has this user not yet accepted the current terms version?
     */
    public function needsConsent(User $user): bool
    {
        $profile = $this->profileFor($user);

        if (! $profile || ! $profile->hasConsented()) {
            return true;
        }

        return $profile->consent_terms_version !== $this->currentTermsVersion();
    }

    public function profileFor(User $user): ?ContributorProfile
    {
        return ContributorProfile::query()->where('user_id', $user->id)->first();
    }

    /**
     * Record acceptance of the current data terms and grant the Contributor
     * capability. Idempotent: re-accepting refreshes the recorded version.
     */
    public function recordConsent(User $user): ContributorProfile
    {
        return DB::transaction(function () use ($user) {
            $profile = ContributorProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
            );

            $profile->forceFill([
                'consented_at' => now(),
                'consent_terms_version' => $this->currentTermsVersion(),
            ])->save();

            // Grant the capability (no KYC gate) and back it with this profile.
            $this->capabilities->grant($user, Capability::Contributor, $profile);

            return $profile->refresh();
        });
    }
}
