<?php

namespace Tests\Feature\Accounts;

use App\Enums\Capability;
use App\Enums\CapabilityStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clients gate the creator studio on what the profile tells them. is_artist was
 * the only signal, so a promoter or seller who is not an artist looked like an
 * ordinary listener — and every link into their own section bounced.
 */
class UserCapabilitiesPayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `status` is deliberately absent from UserCapability::$fillable —
     * lifecycle transitions belong to CapabilityService — so the state has to
     * be forced here rather than mass-assigned.
     */
    private function grant(User $user, Capability $capability, CapabilityStatus $status): void
    {
        $grant = $user->capabilities()->create(['capability' => $capability]);
        $grant->forceFill(['status' => $status->value])->save();
    }

    public function test_profile_reports_granted_capabilities(): void
    {
        $user = User::factory()->create();
        $this->grant($user, Capability::Promoter, CapabilityStatus::Granted);
        $this->grant($user, Capability::Seller, CapabilityStatus::Granted);

        Sanctum::actingAs($user);

        $capabilities = $this->getJson('/api/user/profile')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertContains('promoter', $capabilities);
        $this->assertContains('seller', $capabilities);
    }

    public function test_a_capability_that_is_not_granted_is_withheld(): void
    {
        $user = User::factory()->create();
        $this->grant($user, Capability::Promoter, CapabilityStatus::Pending);
        $this->grant($user, Capability::Seller, CapabilityStatus::Revoked);

        Sanctum::actingAs($user);

        $capabilities = $this->getJson('/api/user/profile')
            ->assertOk()
            ->json('data.capabilities');

        $this->assertNotContains('promoter', $capabilities);
        $this->assertNotContains('seller', $capabilities);
    }

    public function test_an_account_with_no_grants_reports_an_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user/profile')
            ->assertOk()
            ->assertJsonPath('data.capabilities', []);
    }
}
