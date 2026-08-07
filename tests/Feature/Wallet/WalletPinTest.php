<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Services\Wallet\WalletPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WalletPinTest extends TestCase
{
    use RefreshDatabase;

    private function pins(): WalletPinService
    {
        return app(WalletPinService::class);
    }

    public function test_a_user_can_set_a_pin_and_it_is_stored_hashed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/wallet/pin', ['pin' => '4726', 'pin_confirmation' => '4726'])
            ->assertCreated();

        $user->refresh();

        $this->assertNotSame('4726', $user->wallet_pin);
        $this->assertTrue(Hash::check('4726', $user->wallet_pin));
    }

    public function test_the_pin_is_never_exposed_in_serialization(): void
    {
        $user = User::factory()->create();
        $this->pins()->setPin($user, '4726');

        $this->assertArrayNotHasKey('wallet_pin', $user->fresh()->toArray());
    }

    public function test_obvious_pins_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/wallet/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])
            ->assertStatus(422);
    }

    public function test_status_reports_whether_a_pin_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/wallet/pin/status')
            ->assertOk()
            ->assertJsonPath('data.has_pin', false);

        $this->pins()->setPin($user, '4726');

        $this->actingAs($user->fresh())->getJson('/api/wallet/pin/status')
            ->assertOk()
            ->assertJsonPath('data.has_pin', true);
    }

    public function test_verifying_the_correct_pin_unlocks_the_session(): void
    {
        $user = User::factory()->create();
        $this->pins()->setPin($user, '4726');

        $this->actingAs($user->fresh())
            ->postJson('/api/wallet/pin/verify', ['pin' => '4726'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['session_expires_at']]);
    }

    public function test_a_wrong_pin_is_rejected_and_counts_down_attempts(): void
    {
        $user = User::factory()->create();
        $this->pins()->setPin($user, '4726');

        $this->actingAs($user->fresh())
            ->postJson('/api/wallet/pin/verify', ['pin' => '9999'])
            ->assertStatus(422);

        $this->assertSame(1, (int) $user->fresh()->wallet_pin_failed_attempts);
    }

    public function test_the_pin_locks_after_too_many_wrong_attempts(): void
    {
        config(['wallet.pin.max_attempts' => 3]);

        $user = User::factory()->create();
        $this->pins()->setPin($user, '4726');
        $fresh = $user->fresh();

        for ($i = 0; $i < 3; $i++) {
            $this->pins()->verify($fresh, '9999');
            $fresh = $fresh->fresh();
        }

        $this->assertTrue($this->pins()->isLocked($fresh));
        $this->assertSame(0, $this->pins()->remainingAttempts($fresh));
    }

    public function test_changing_the_pin_requires_the_current_one(): void
    {
        $user = User::factory()->create();
        $this->pins()->setPin($user, '4726');

        $this->actingAs($user->fresh())
            ->putJson('/api/wallet/pin', [
                'current_pin' => '0000',
                'pin' => '8391',
                'pin_confirmation' => '8391',
            ])
            ->assertStatus(422);

        $this->actingAs($user->fresh())
            ->putJson('/api/wallet/pin', [
                'current_pin' => '4726',
                'pin' => '8391',
                'pin_confirmation' => '8391',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('8391', $user->fresh()->wallet_pin));
    }

    public function test_the_gate_blocks_money_movement_when_enforcement_is_on(): void
    {
        config(['wallet.pin.enforce' => true]);

        $user = User::factory()->create();

        // No PIN yet — the gate asks the frontend to run setup.
        $this->actingAs($user)
            ->postJson('/api/credits/transfer', ['recipient' => 'someone', 'amount' => 10])
            ->assertStatus(423)
            ->assertJsonPath('pin_status', 'setup_required');
    }

    public function test_the_gate_is_dark_by_default_so_existing_flows_keep_working(): void
    {
        // Enforcement defaults to off; the middleware must not intercept.
        $this->assertFalse((bool) config('wallet.pin.enforce'));

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/credits/transfer', ['recipient' => 'someone', 'amount' => 10]);

        $this->assertNotSame(423, $response->status());
    }
}
