<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Promotions\Models\PromoterProfile;
use App\Modules\Promotions\Services\PromoterOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoterOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_onboard_directly_surfaces_errors(): void
    {
        $user = User::factory()->create();

        // Call the service directly so any exception propagates (the controller
        // swallows it into a generic 500).
        $profile = app(PromoterOnboardingService::class)->onboard($user, [
            'display_name' => 'Test Promoter',
            'platforms' => ['instagram'],
            'niches' => ['afrobeats'],
            'audience_regions' => ['ug'],
        ]);

        $this->assertInstanceOf(PromoterProfile::class, $profile);
    }

    public function test_onboarding_succeeds_without_a_store(): void
    {
        // Mirrors the resilient path: if the store subsystem is unavailable, the
        // promoter profile is still created (with a null store_id).
        config(['promotions.auto_provision_store' => false]);

        $user = User::factory()->create();

        $profile = app(PromoterOnboardingService::class)->onboard($user, [
            'display_name' => 'No Store Promoter',
        ]);

        $this->assertNull($profile->store_id);
        $this->assertDatabaseHas('promoter_profiles', [
            'user_id' => $user->id,
            'store_id' => null,
        ]);
    }

    public function test_user_can_onboard_via_endpoint(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/promoters/onboard', [
            'display_name' => 'Test Promoter',
            'platforms' => ['instagram'],
            'niches' => ['afrobeats'],
            'audience_regions' => ['ug'],
        ])->assertCreated();

        $this->assertDatabaseHas('promoter_profiles', ['user_id' => $user->id]);
    }
}
