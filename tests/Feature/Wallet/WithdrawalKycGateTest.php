<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Services\Kyc\KycService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The withdrawal gate used to read the payout destination from the artist
 * profile only, so a listener with no artist profile could satisfy every other
 * requirement and still be told `payout_method` was missing — permanently
 * unable to withdraw from their own wallet.
 */
class WithdrawalKycGateTest extends TestCase
{
    use RefreshDatabase;

    private function kyc(): KycService
    {
        return app(KycService::class);
    }

    private function verifiedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'kyc_status' => 'verified',
            'kyc_verified_at' => now(),
            'phone' => '256772123456',
        ], $attributes));
    }

    public function test_a_listener_with_a_phone_can_satisfy_the_payout_step(): void
    {
        $user = $this->verifiedUser();

        $this->assertNull($user->artistProfile);
        $this->assertNotContains('payout_method', $this->kyc()->missingStepsFor($user, KycService::ACTION_WITHDRAWAL));
    }

    public function test_a_listener_without_a_phone_still_misses_the_payout_step(): void
    {
        $user = $this->verifiedUser(['phone' => null]);

        $this->assertContains('payout_method', $this->kyc()->missingStepsFor($user, KycService::ACTION_WITHDRAWAL));
    }

    public function test_unverified_kyc_still_blocks_withdrawal(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'partial',
            'kyc_verified_at' => null,
            'phone' => '256772123456',
        ]);

        $missing = $this->kyc()->missingStepsFor($user, KycService::ACTION_WITHDRAWAL);

        $this->assertContains('kyc_verified', $missing, 'Identity verification must still gate money leaving the platform.');
        $this->assertFalse($this->kyc()->eligibleFor($user, KycService::ACTION_WITHDRAWAL));
    }

    public function test_the_withdraw_endpoint_returns_a_structured_403_when_gated(): void
    {
        $user = User::factory()->create(['kyc_status' => 'partial', 'kyc_verified_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/wallet/withdraw', [
                'amount' => 1000,
                'phone' => '256772123456',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'kyc_required')
            ->assertJsonPath('redirect', '/account/verify-identity')
            ->assertJsonStructure(['missing_steps']);
    }
}
