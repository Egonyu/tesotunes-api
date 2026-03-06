<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PaymentProcessingTest extends TestCase
{
    use DatabaseTransactions;

    private string $subscriptionUrl = '/api/payments/subscription';

    // ━━━ Subscription Payment ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_authenticated_user_can_initiate_subscription_payment(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = SubscriptionPlan::factory()->create();

        $this->mock(PaymentService::class, function ($mock) {
            $mock->shouldReceive('processSubscriptionPayment')
                ->once()
                ->andReturn(['success' => true, 'payment_id' => 1, 'message' => 'Payment initiated']);
        });

        $response = $this->actingAs($user)->postJson($this->subscriptionUrl, [
            'plan_id' => $plan->id,
            'payment_method' => 'mobile_money',
            'phone_number' => '256700000000',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data']);
    }

    public function test_subscription_requires_authentication(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $response = $this->postJson($this->subscriptionUrl, [
            'plan_id' => $plan->id,
            'payment_method' => 'mobile_money',
            'phone_number' => '256700000000',
        ]);

        $response->assertUnauthorized();
    }

    public function test_subscription_validates_plan_exists(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson($this->subscriptionUrl, [
            'plan_id' => 99999,
            'payment_method' => 'mobile_money',
            'phone_number' => '256700000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id']);
    }

    public function test_subscription_validates_payment_method(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = SubscriptionPlan::factory()->create();

        $response = $this->actingAs($user)->postJson($this->subscriptionUrl, [
            'plan_id' => $plan->id,
            'payment_method' => 'bitcoin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_subscription_requires_phone_for_mobile_money(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = SubscriptionPlan::factory()->create();

        $response = $this->actingAs($user)->postJson($this->subscriptionUrl, [
            'plan_id' => $plan->id,
            'payment_method' => 'mobile_money',
            // Missing phone_number
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_subscription_does_not_require_phone_for_credits(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = SubscriptionPlan::factory()->create();

        $this->mock(PaymentService::class, function ($mock) {
            $mock->shouldReceive('processSubscriptionPayment')
                ->andReturn(['success' => true, 'payment_id' => 1]);
        });

        $response = $this->actingAs($user)->postJson($this->subscriptionUrl, [
            'plan_id' => $plan->id,
            'payment_method' => 'credits',
        ]);

        // Should not fail on phone_number validation
        $response->assertJsonMissingValidationErrors(['phone_number']);
    }

    // ━━━ Refund ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function test_payment_owner_can_request_refund(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
        ]));

        $response = $this->actingAs($user)->postJson("/api/payments/{$payment->id}/refund", [
            'reason' => 'Changed my mind',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_non_owner_cannot_refund_someone_elses_payment(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create([
            'user_id' => $owner->id,
            'status' => 'completed',
        ]));

        $response = $this->actingAs($other)->postJson("/api/payments/{$payment->id}/refund", [
            'reason' => 'Theft attempt',
        ]);

        $response->assertForbidden();
    }

    public function test_refund_requires_authentication(): void
    {
        $payment = Payment::withoutEvents(fn () => Payment::factory()->create(['status' => 'completed']));

        $response = $this->postJson("/api/payments/{$payment->id}/refund");

        $response->assertUnauthorized();
    }
}
