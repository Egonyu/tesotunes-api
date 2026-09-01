<?php

namespace Tests\Feature\Wallet;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletInFlightTest extends TestCase
{
    use RefreshDatabase;

    private function payment(User $user, array $attributes): Payment
    {
        $payment = new Payment;
        $payment->forceFill(array_merge([
            'user_id' => $user->id,
            'payment_type' => 'wallet_topup',
            'amount' => 5000,
            'currency' => 'UGX',
            'status' => Payment::STATUS_PENDING,
            'transaction_reference' => 'TT-'.uniqid(),
        ], $attributes))->save();

        return $payment;
    }

    public function test_pending_money_is_reported_on_the_wallet(): void
    {
        $user = User::factory()->create();
        $this->payment($user, ['status' => Payment::STATUS_PENDING, 'amount' => 2000]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonPath('data.in_flight.0.amount', 2000)
            ->assertJsonPath('data.in_flight.0.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.in_flight.0.direction', 'in');
    }

    public function test_withdrawals_are_reported_as_outbound(): void
    {
        $user = User::factory()->create();
        $this->payment($user, ['payment_type' => 'withdrawal', 'status' => Payment::STATUS_PROCESSING]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonPath('data.in_flight.0.direction', 'out');
    }

    public function test_money_settling_for_over_a_day_is_flagged_stale(): void
    {
        $user = User::factory()->create();
        $this->payment($user, [
            'status' => Payment::STATUS_PROCESSING,
            'created_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonPath('data.in_flight.0.is_stale', true);
    }

    public function test_fresh_pending_money_is_not_flagged_stale(): void
    {
        $user = User::factory()->create();
        $this->payment($user, ['created_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonPath('data.in_flight.0.is_stale', false);
    }

    public function test_a_recent_failure_is_surfaced_with_its_reason(): void
    {
        $user = User::factory()->create();
        $this->payment($user, [
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => 'Payment was declined by the provider.',
            'created_at' => now()->subHours(2),
        ]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonPath('data.in_flight.0.status', Payment::STATUS_FAILED)
            ->assertJsonPath('data.in_flight.0.failure_reason', 'Payment was declined by the provider.');
    }

    public function test_old_failures_and_completed_payments_are_not_reported(): void
    {
        $user = User::factory()->create();
        $this->payment($user, ['status' => Payment::STATUS_FAILED, 'created_at' => now()->subDays(30)]);
        $this->payment($user, ['status' => Payment::STATUS_COMPLETED]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonCount(0, 'data.in_flight');
    }

    public function test_one_user_never_sees_another_users_money(): void
    {
        $user = User::factory()->create();
        $this->payment(User::factory()->create(), ['amount' => 999000]);

        $this->actingAs($user)
            ->getJson('/api/payments/wallet')
            ->assertOk()
            ->assertJsonCount(0, 'data.in_flight');
    }
}
