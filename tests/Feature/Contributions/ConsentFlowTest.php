<?php

namespace Tests\Feature\Contributions;

use App\Enums\Capability;
use App\Models\User;
use App\Modules\Contributions\Models\ContributorProfile;
use App\Modules\Contributions\Services\ConsentService;
use App\Services\Accounts\CapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ConsentFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): ConsentService
    {
        return app(ConsentService::class);
    }

    public function test_new_user_needs_consent(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->service()->needsConsent($user));
        $this->assertNull($this->service()->profileFor($user));
    }

    public function test_recording_consent_creates_profile_and_grants_capability(): void
    {
        $user = User::factory()->create();

        $profile = $this->service()->recordConsent($user);

        $this->assertInstanceOf(ContributorProfile::class, $profile);
        $this->assertNotNull($profile->consented_at);
        $this->assertSame(config('contributions.terms_version'), $profile->consent_terms_version);
        $this->assertSame(ContributorProfile::TIER_NOVICE, $profile->tier);

        $this->assertFalse($this->service()->needsConsent($user->fresh()));

        // The Contributor capability is granted (no KYC required) and backed by
        // the contributor profile.
        $this->assertTrue(app(CapabilityService::class)->has($user, Capability::Contributor));
        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $user->id,
            'capability' => Capability::Contributor->value,
            'status' => 'granted',
            'profile_type' => ContributorProfile::class,
            'profile_id' => $profile->id,
        ]);
    }

    public function test_recording_consent_is_idempotent(): void
    {
        $user = User::factory()->create();

        $this->service()->recordConsent($user);
        $this->service()->recordConsent($user);

        $this->assertSame(1, ContributorProfile::where('user_id', $user->id)->count());
        $this->assertSame(1, \DB::table('user_capabilities')
            ->where('user_id', $user->id)
            ->where('capability', Capability::Contributor->value)
            ->count());
    }

    public function test_a_new_terms_version_requires_reconsent(): void
    {
        $user = User::factory()->create();
        $this->service()->recordConsent($user);
        $this->assertFalse($this->service()->needsConsent($user->fresh()));

        // Bump the published terms version; the prior consent is now stale.
        config(['contributions.terms_version' => '2099-01-01']);

        $this->assertTrue($this->service()->needsConsent($user->fresh()));
    }
}
